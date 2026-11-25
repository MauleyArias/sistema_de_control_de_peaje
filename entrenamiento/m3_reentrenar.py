"""M3 (llantas, 5 keypoints) - reentrenamiento en una GPU en la nube (Vast.ai, RunPod o Colab). Lo usa M3_reentrenar_nube.ipynb.

  python m3_reentrenar.py preparar   descarga los datasets públicos y arma el dataset mezclado
  python m3_reentrenar.py entrenar   ajuste fino desde el M3 base
  python m3_reentrenar.py comparar   compara los modelos anteriores con el nuevo

Keypoints: centro, abajo, arriba, izquierda, derecha. En los datasets públicos se generan a partir
de la caja de cada llanta; en las fotos propias los pone el M3 base (pseudoetiquetas).
"""
import csv
import glob
import json
import os
import random
import re
import shutil
import sys
from pathlib import Path

import cv2
import numpy as np
import yaml

BASE = Path(os.getenv("M3_DIR", "/workspace/m3"))
ENTRADA = BASE / "m3_entrada"          # contenido de m3_entrada.zip
DESCARGAS = BASE / "descargas"
MIX = BASE / "mix"
NUEVO = BASE / "runs" / "m3_v2" / "weights" / "best.pt"
FUENTES = {"axle": ("eternal-wiecw", "axle-bjpov"), "truckaxel": ("rajan-28noy", "truck-axel")}
MAX_IMG = int(os.getenv("M3_MAX_IMG", "700"))      # por dataset público
WORKERS = int(os.getenv("M3_WORKERS", "4"))
PATRON_LLANTA = re.compile(r"^(axles?|axels?|wheels?|tires?|tyres?|llantas?|ruedas?)$", re.I)
PATRON_CAMION = re.compile(r"^(\d)\s*-?\s*ax", re.I)
COCO = {"car": "auto", "bus": "bus", "truck": "camion", "motorcycle": "moto"}


# ------------------------------------------------------------------ datos
def descargar():
    rf = None
    for nombre, (w, p) in FUENTES.items():
        destino = DESCARGAS / nombre
        if (destino / "data.yaml").exists():
            print(nombre, "ya estaba descargado")
            continue
        if rf is None:
            from roboflow import Roboflow
            rf = Roboflow(api_key=os.environ["ROBOFLOW_API_KEY"])
        proyecto = rf.workspace(w).project(p)
        v = max(int(re.findall(r"(\d+)$", str(x.version))[0]) for x in proyecto.versions())
        proyecto.version(v).download("yolov8", location=str(destino))
        print(nombre, "versión", v, "OK")


def caja(partes):
    v = list(map(float, partes[1:]))
    if len(v) == 4:
        return v
    xs, ys = v[0::2], v[1::2]
    return [(min(xs) + max(xs)) / 2, (min(ys) + max(ys)) / 2, max(xs) - min(xs), max(ys) - min(ys)]


def limitar(t):
    return min(max(t, 0.0), 1.0)


def linea_pose(cx, cy, w, h):
    x1, x2 = limitar(cx - w / 2), limitar(cx + w / 2)
    y1, y2 = limitar(cy - h / 2), limitar(cy + h / 2)
    cx, cy, w, h = (x1 + x2) / 2, (y1 + y2) / 2, x2 - x1, y2 - y1
    pts = [(cx, cy), (cx, y2), (cx, y1), (x1, cy), (x2, cy)]
    return f"0 {cx:.6f} {cy:.6f} {w:.6f} {h:.6f} " + " ".join(f"{x:.6f} {y:.6f} 2" for x, y in pts)


def original(ruta):
    """Nombre de la foto sin el sufijo que Roboflow agrega a cada copia aumentada."""
    return Path(ruta).stem.split(".rf.")[0]


def grupo(nombre, ruta):
    """Las copias aumentadas (y en axle las fotos del mismo camión) van juntas a train o a valid."""
    base = original(ruta)
    m = re.match(r"^(truck_\d+)_", base) if nombre == "axle" else None
    return m.group(1) if m else base


def guardar(parte, nombre, img, lineas):
    cv2.imwrite(str(MIX / parte / "images" / f"{nombre}.jpg"), img)
    (MIX / parte / "labels" / f"{nombre}.txt").write_text("\n".join(lineas) + "\n")


def agregar_publico(nombre, conteos):
    d = DESCARGAS / nombre
    names = yaml.safe_load(open(d / "data.yaml", encoding="utf-8"))["names"]
    if isinstance(names, dict):
        names = [names[k] for k in sorted(names)]
    llanta = {i for i, n in enumerate(names) if PATRON_LLANTA.match(str(n).strip())}
    camion = {}
    for i, n in enumerate(names):
        m = PATRON_CAMION.match(str(n).strip())
        if m:
            camion[i] = int(m.group(1))
    grupos = {}
    for lbl in sorted(d.glob("*/labels/*.txt")):
        grupos.setdefault(grupo(nombre, lbl), []).append(lbl)
    claves = sorted(grupos)
    random.Random(42).shuffle(claves)
    n_valid = round(MAX_IMG * 0.15)
    tope = {"valid": n_valid, "train": MAX_IMG - n_valid}
    total = {"train": 0, "valid": 0}
    for g in claves:
        # Primero se llena validación con grupos completos; los demás son de entrenamiento.
        parte = "valid" if total["valid"] < tope["valid"] and len(claves) > 1 else "train"
        rutas = grupos[g]
        if parte == "valid":           # en validación, una sola copia de cada foto
            rutas = list({original(r): r for r in rutas}.values())
        for lbl in rutas:
            if total[parte] >= tope[parte]:
                break
            llantas, camiones = [], []
            for linea in lbl.read_text().splitlines():
                p = linea.split()
                if len(p) < 5:
                    continue
                k = int(float(p[0]))
                if k in llanta:
                    llantas.append(caja(p))
                elif k in camion:
                    camiones.append((caja(p), camion[k]))
            rutas_img = list((lbl.parent.parent / "images").glob(glob.escape(lbl.stem) + ".*"))
            img = cv2.imread(str(rutas_img[0])) if rutas_img and llantas else None
            if img is None:
                continue
            alto, ancho = img.shape[:2]
            base = f"{nombre}_{lbl.stem}"
            if not camion:
                guardar(parte, base, img, [linea_pose(*c) for c in llantas])
                total[parte] += 1
                continue
            # truck axel: se recorta cada camión etiquetado. En la foto completa los demás vehículos
            # no tienen etiquetas y le enseñarían al modelo a NO ver sus llantas.
            for j, ((cx, cy, bw, bh), n) in enumerate(camiones):
                if total[parte] >= tope[parte]:
                    break
                x1 = int(max(0.0, cx - bw * 0.58) * ancho)
                x2 = int(min(1.0, cx + bw * 0.58) * ancho)
                y1 = int(max(0.0, cy - bh * 0.58) * alto)
                y2 = int(min(1.0, cy + bh * 0.58) * alto)
                w, h = x2 - x1, y2 - y1
                if w < 60 or h < 60:
                    continue
                dentro = [((lx * ancho - x1) / w, (ly * alto - y1) / h, lw * ancho / w, lh * alto / h)
                          for lx, ly, lw, lh in llantas
                          if x1 <= lx * ancho <= x2 and y1 <= ly * alto <= y2]
                if not dentro:
                    continue
                guardar(parte, f"{base}_{j}", img[y1:y2, x1:x2], [linea_pose(*c) for c in dentro])
                total[parte] += 1
                if parte == "valid":
                    conteos[str(MIX / parte / "images" / f"{base}_{j}.jpg")] = n
    print(f"{nombre}: {len(claves)} grupos | clase llanta: {[names[i] for i in sorted(llanta)]} | {total}")


def fotos_propias():
    with open(ENTRADA / "ejes_reales.csv", encoding="utf-8") as f:
        return list(csv.DictReader(f))


def agregar_propias(base_pt, copias=4):
    """Solo las fotos 'train' donde el M3 base ve exactamente los ejes reales."""
    from ultralytics import YOLO
    modelo, usadas, total = YOLO(str(base_pt)), 0, 0
    for fila in fotos_propias():
        if fila["uso"] != "train":
            continue
        total += 1
        img = cv2.imread(str(ENTRADA / "fotos" / fila["foto"]))
        r = modelo.predict(img, conf=0.4, verbose=False)[0]
        if len(r.boxes) != int(fila["ejes"]):
            continue
        lineas = []
        for b, k in zip(r.boxes.xywhn.tolist(), r.keypoints.xyn.tolist()):
            pts = " ".join(f"{limitar(x):.6f} {limitar(y):.6f} 2" for x, y in k)
            lineas.append("0 " + " ".join(f"{v:.6f}" for v in b) + " " + pts)
        for c in range(copias):
            guardar("train", f"mio_{Path(fila['foto']).stem}_{c}", img, lineas)
        usadas += 1
    print(f"fotos propias: {usadas} de {total} (x{copias} copias)")


def preparar():
    BASE.mkdir(parents=True, exist_ok=True)
    descargar()
    shutil.rmtree(MIX, ignore_errors=True)
    for parte in ("train", "valid"):
        for sub in ("images", "labels"):
            (MIX / parte / sub).mkdir(parents=True)
    conteos = {}
    for nombre in FUENTES:
        agregar_publico(nombre, conteos)
    agregar_propias(ENTRADA / "m3_23nov_tarde.pt")
    (MIX / "conteos.json").write_text(json.dumps(conteos))
    with open(MIX / "data.yaml", "w") as f:
        yaml.safe_dump({"path": str(MIX), "train": "train/images", "val": "valid/images", "names": {0: "llanta"},
                        "kpt_shape": [5, 3], "flip_idx": [0, 1, 2, 4, 3]}, f)
    for parte in ("train", "valid"):
        print(parte, len(list((MIX / parte / "images").iterdir())), "imágenes")
    print("camiones con ejes reales en valid:", len(conteos))


# ------------------------------------------------------------------ entrenamiento
def entrenar():
    from ultralytics import YOLO
    YOLO(str(ENTRADA / "m3_23nov_tarde.pt")).train(
        data=str(MIX / "data.yaml"), epochs=int(os.getenv("M3_EPOCAS", "60")), patience=15,
        imgsz=int(os.getenv("M3_IMGSZ", "640")), batch=int(os.getenv("M3_BATCH", "16")),
        workers=WORKERS, seed=42, project=str(BASE / "runs"), name="m3_v2", exist_ok=True)
    print("ENTRENAMIENTO TERMINADO")


# ------------------------------------------------------------------ comparación
def contar(res, zona=None):
    """Cuenta ejes; `zona` descarta llantas fuera del vehículo cuando se analiza la foto completa."""
    llantas = []
    if len(res.boxes):
        for kp, (x1, y1, x2, y2) in zip(res.keypoints.xy.tolist(), res.boxes.xyxy.tolist()):
            x, y = kp[0]
            if x <= 0 and y <= 0:
                x, y = (x1 + x2) / 2, (y1 + y2) / 2
            if zona:
                m = 0.1 * (zona[2] - zona[0])
                if not (zona[0] - m <= x <= zona[2] + m and zona[1] - m <= y <= zona[3] + m):
                    continue
            llantas.append((round(x), x2 - x1))
    ejes = []
    for p in sorted(llantas):
        if not ejes or p[0] - ejes[-1][0] > 0.5 * max(p[1], ejes[-1][1], 20):
            ejes.append(p)
    return len(ejes)


def vehiculo(m2, coco, img):
    """Caja del vehículo más grande: M2 propio y, si no ve nada, COCO (como el endpoint)."""
    umbral = float(os.getenv("UMBRAL_M2", "0.25"))
    for modelo, mapa in ((m2, None), (coco, COCO)):
        mejor = None
        r = modelo.predict(img, conf=umbral, verbose=False)[0]
        for b in r.boxes:
            n = r.names[int(b.cls)].lower()
            if (mapa.get(n) if mapa else n) not in ("moto", "auto", "minibus", "camion", "bus"):
                continue
            x1, y1, x2, y2 = b.xyxy[0].tolist()
            area = (x2 - x1) * (y2 - y1)
            if mejor is None or area > mejor[0]:
                mejor = (area, [x1, y1, x2, y2])
        if mejor:
            return mejor[1]
    return None


def recortar(img, zona):
    """Recorte del vehículo con margen del 8 %, igual que contar_ejes() en inferencia/app.py."""
    if not zona:
        return img
    x1, y1, x2, y2 = (int(v) for v in zona)
    mx, my = int(0.08 * (x2 - x1)), int(0.08 * (y2 - y1))
    recorte = img[max(0, y1 - my):y2 + my, max(0, x1 - mx):x2 + mx]
    return recorte if recorte.size else img


def resumen(reales, pred):
    e = np.abs(np.array(reales) - np.array(pred))
    return f"exacto {int((e == 0).sum())}/{len(e)} ({(e == 0).mean():.0%}) | MAE {e.mean():.2f}"


def comparar(solo_propias=False):
    from ultralytics import YOLO
    rutas = {"actual (repo)": ENTRADA / "m3_actual.pt", "23nov tarde": ENTRADA / "m3_23nov_tarde.pt", "NUEVO": NUEVO}
    m2, coco = YOLO(str(ENTRADA / "m2_seg.pt")), YOLO(str(ENTRADA / "yolo11n-seg.pt"))
    filas = [f for f in fotos_propias() if f["uso"] == "test"]
    imgs = [cv2.imread(str(ENTRADA / "fotos" / f["foto"])) for f in filas]
    zonas = [vehiculo(m2, coco, im) for im in imgs]
    conteos = {} if solo_propias else json.loads((MIX / "conteos.json").read_text())
    for k, ruta in rutas.items():
        if not ruta.exists():
            continue
        modelo = YOLO(str(ruta))
        print(f"\n=== {k} ===")
        # Los conteos van antes de val(): val() fusiona capas del modelo.
        completas = [contar(modelo.predict(im, conf=0.25, verbose=False)[0], z) for im, z in zip(imgs, zonas)]
        recortes = [contar(modelo.predict(recortar(im, z), conf=0.25, verbose=False)[0]) for im, z in zip(imgs, zonas)]
        for modo, pred in (("foto completa", completas), ("recorte (endpoint)", recortes)):
            print(f"Fotos propias, {modo}:")
            for cat in ("auto", "camion", "bus"):
                ix = [i for i, f in enumerate(filas) if f["categoria"] == cat]
                print(f"  {cat:7s}", resumen([int(filas[i]["ejes"]) for i in ix], [pred[i] for i in ix]))
            print("  TOTAL  ", resumen([int(f["ejes"]) for f in filas], pred))
        if conteos:
            p = [contar(modelo.predict(r, conf=0.25, verbose=False)[0]) for r in conteos]
            print("Públicos: ejes de camión", resumen(list(conteos.values()), p))
        if not solo_propias:
            v = modelo.val(data=str(MIX / "data.yaml"), split="val", verbose=False, plots=False, workers=WORKERS)
            print(f"Públicos: POSE mAP50={v.pose.map50:.3f} mAP50-95={v.pose.map:.3f} | CAJA mAP50={v.box.map50:.3f}")


if __name__ == "__main__":
    {"preparar": preparar, "entrenar": entrenar, "comparar": comparar,
     "comparar_propias": lambda: comparar(True)}[sys.argv[1]]()
