"""Endpoint único de inferencia del sistema de peaje (GPU en Vast.ai; también RunPod o Google Colab).

Modelos:
  M1  Detección de placas (YOLOv9-t ONNX, proyecto anterior) + OCR (fast-plate-ocr; EasyOCR opcional)
  M2  Segmentación de instancias (YOLO-seg propio) -> categoría del vehículo
  M3  Keypoints (YOLO-pose propio) sobre las ruedas -> número de ejes
  M4  Segmentación de personas (YOLO-seg COCO) -> pasajeros visibles (punto adicional)

POST /inferir  (multipart: imagen=<jpg>)  ->  JSON con placa, confianza, categoría, ejes, pasajeros y latencia.
Protegido con la cabecera X-API-Key (variable INFERENCE_API_KEY).
"""
import base64
import os
import re
import time
from contextlib import asynccontextmanager
from functools import lru_cache
from pathlib import Path

import cv2
import numpy as np
import onnxruntime as ort
from fastapi import Depends, FastAPI, File, Header, HTTPException, UploadFile

BASE = Path(__file__).parent
MODELOS = BASE / "modelos"
API_KEY = os.getenv("INFERENCE_API_KEY", "")
OCR_MOTOR = os.getenv("OCR_MOTOR", "fastocr")  # fastocr | easyocr
UMBRAL_PLACA = float(os.getenv("UMBRAL_PLACA", "0.4"))
UMBRAL_M2 = float(os.getenv("UMBRAL_M2", "0.25"))
UMBRAL_M3 = float(os.getenv("UMBRAL_M3", "0.25"))
KPT_CENTRO = int(os.getenv("KPT_CENTRO", "0"))  # índice del keypoint "centro de la llanta"
DISPOSITIVO = os.getenv("DISPOSITIVO", "0" if os.getenv("CUDA_VISIBLE_DEVICES", "") != "" else "cpu")

CATEGORIAS = ["moto", "auto", "minibus", "camion", "bus"]
# Respaldo si aún no existe m2_seg.pt: clases COCO -> categoría del peaje.
COCO_A_CATEGORIA = {"motorcycle": "moto", "car": "auto", "bus": "bus", "truck": "camion"}
EJES_POR_DEFECTO = {"moto": 2, "auto": 2, "minibus": 2, "camion": 2, "bus": 2}

@asynccontextmanager
async def ciclo_de_vida(_app):
    # Precarga: el primer frame de la caseta no debe pagar la carga de los modelos.
    if os.getenv("PRECARGAR", "1") == "1":
        prueba = np.full((480, 640, 3), 114, np.uint8)
        detectar_placas(prueba)
        if OCR_MOTOR == "easyocr":
            lector_easyocr()
        else:
            lector_fastocr()
        _, _, caja, _, _ = clasificar_vehiculo(prueba)
        contar_ejes(prueba, caja)
        contar_pasajeros(prueba, caja)
    yield


app = FastAPI(title="Peaje - Inferencia", version="1.0.0", lifespan=ciclo_de_vida)


def verificar_clave(x_api_key: str = Header(default="")):
    if API_KEY and x_api_key != API_KEY:
        raise HTTPException(status_code=401, detail="X-API-Key inválida")


# ---------------------------------------------------------------- M1: placas
LADO = 384


@lru_cache(maxsize=1)
def sesion_placas():
    proveedores = [p for p in ("CUDAExecutionProvider", "CPUExecutionProvider") if p in ort.get_available_providers()]
    return ort.InferenceSession(str(MODELOS / "yolo-v9-t-384-license-plates-end2end.onnx"), providers=proveedores)


def detectar_placas(imagen):
    alto, ancho = imagen.shape[:2]
    escala = min(LADO / alto, LADO / ancho)
    nuevo_ancho, nuevo_alto = round(ancho * escala), round(alto * escala)
    margen_x, margen_y = (LADO - nuevo_ancho) / 2, (LADO - nuevo_alto) / 2
    lienzo = cv2.resize(imagen, (nuevo_ancho, nuevo_alto))
    lienzo = cv2.copyMakeBorder(lienzo, round(margen_y - 0.1), round(margen_y + 0.1), round(margen_x - 0.1),
                                round(margen_x + 0.1), cv2.BORDER_CONSTANT, value=(114, 114, 114))
    entrada = (lienzo[:, :, ::-1].transpose(2, 0, 1) / 255.0).astype(np.float32)[None]
    sesion = sesion_placas()
    salida = sesion.run(None, {sesion.get_inputs()[0].name: entrada})[0]
    cajas = []
    for _, x1, y1, x2, y2, _, conf in salida:
        if conf < UMBRAL_PLACA:
            continue
        x1, x2 = [(v - margen_x) / escala for v in (x1, x2)]
        y1, y2 = [(v - margen_y) / escala for v in (y1, y2)]
        cajas.append((int(max(0, x1)), int(max(0, y1)), int(min(ancho, x2)), int(min(alto, y2)), float(conf)))
    return sorted(cajas, key=lambda c: c[4], reverse=True)


@lru_cache(maxsize=1)
def lector_fastocr():
    from fast_plate_ocr import LicensePlateRecognizer
    return LicensePlateRecognizer(onnx_model_path=MODELOS / "cct_xs_v2_global.onnx",
                                  plate_config_path=MODELOS / "cct_xs_v2_global_plate_config.yaml")


@lru_cache(maxsize=1)
def lector_easyocr():
    import easyocr
    return easyocr.Reader(["en"], gpu=DISPOSITIVO != "cpu", verbose=False)


def normalizar(texto):
    return re.sub(r"[^A-Z0-9]", "", (texto or "").upper())


def leer_placa(imagen, caja):
    x1, y1, x2, y2, _ = caja
    recorte = imagen[y1:y2, x1:x2]
    if not recorte.size:
        return "", 0.0
    if OCR_MOTOR == "easyocr":
        gris = cv2.resize(recorte.max(axis=2), None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC)
        lecturas = lector_easyocr().readtext(gris, allowlist="ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789")
        if not lecturas:
            return "", 0.0
        altura = lambda l: max(p[1] for p in l[0]) - min(p[1] for p in l[0])
        maxima = max(altura(l) for l in lecturas)
        lecturas = [l for l in lecturas if altura(l) >= maxima * 0.65]
        lecturas.sort(key=lambda l: (l[0][0][1] // 40, l[0][0][0]))
        return "".join(t for _, t, _ in lecturas), float(np.mean([c for _, _, c in lecturas]))
    pred = lector_fastocr().run_one(cv2.cvtColor(recorte, cv2.COLOR_BGR2RGB), return_confidence=True)
    conf = float(pred.char_probs.mean()) if getattr(pred, "char_probs", None) is not None else 0.0
    return pred.plate, conf


# ---------------------------------------------------------------- M2, M3, M4 (Ultralytics)
@lru_cache(maxsize=None)
def yolo(nombre):
    from ultralytics import YOLO
    return YOLO(str(nombre))


def modelo_m2():
    propio = MODELOS / "m2_seg.pt"
    return (yolo(propio), True) if propio.exists() else (yolo("yolo11n-seg.pt"), False)


def modelo_m3():
    propio = MODELOS / "m3_pose.pt"
    return yolo(propio) if propio.exists() else None


def modelo_pasajeros():
    return yolo(MODELOS / "m4_pasajeros_seg.pt") if (MODELOS / "m4_pasajeros_seg.pt").exists() else yolo("yolo11n-seg.pt")


def clasificar_vehiculo(imagen):
    """M2: (categoria, confianza, caja, mascara_poligono, propio) del vehículo más grande.

    Usa el modelo propio; si no detecta ningún vehículo, recurre al modelo COCO de respaldo
    (car/bus/truck/motorcycle). El campo m2_modelo del JSON indica cuál respondió.
    """
    modelo, propio = modelo_m2()
    resultado = _vehiculo_mas_grande(modelo, propio, imagen)
    if resultado[0] is None and propio:
        resultado = _vehiculo_mas_grande(yolo("yolo11n-seg.pt"), False, imagen)
    return resultado


def _vehiculo_mas_grande(modelo, propio, imagen):
    res = modelo.predict(imagen, conf=UMBRAL_M2, device=DISPOSITIVO, verbose=False, retina_masks=False)[0]
    mejor = None
    for i, caja in enumerate(res.boxes):
        nombre = res.names[int(caja.cls)].lower()
        categoria = nombre if propio else COCO_A_CATEGORIA.get(nombre)
        if categoria not in CATEGORIAS:
            continue
        x1, y1, x2, y2 = caja.xyxy[0].tolist()
        area = (x2 - x1) * (y2 - y1)
        if mejor is None or area > mejor[0]:
            poligono = res.masks.xy[i].tolist() if res.masks is not None else []
            mejor = (area, categoria, float(caja.conf), [int(x1), int(y1), int(x2), int(y2)], poligono)
    if mejor is None:
        return None, 0.0, None, [], propio
    return mejor[1], mejor[2], mejor[3], mejor[4], propio


def contar_ejes(imagen, caja_vehiculo):
    """M3: cada llanta visible de costado es una instancia de pose = un eje.

    El modelo propio marca 5 keypoints por llanta (centro, abajo, arriba, izquierda, derecha);
    se usa el keypoint KPT_CENTRO (0) como posición de la llanta. Si el modelo tuviera 1 solo
    keypoint, también funciona.

    M3 analiza solo el recorte del vehículo (M2) con un margen del 8 %: se entrenó con camiones
    recortados y así las llantas ocupan más píxeles. Sin vehículo, analiza la imagen completa.
    """
    modelo = modelo_m3()
    if modelo is None:
        return None, []
    zona, ox, oy = imagen, 0, 0
    if caja_vehiculo:
        x1, y1, x2, y2 = caja_vehiculo
        mx, my = int(0.08 * (x2 - x1)), int(0.08 * (y2 - y1))
        recorte = imagen[max(0, y1 - my):y2 + my, max(0, x1 - mx):x2 + mx]
        if recorte.size:
            zona, ox, oy = recorte, max(0, x1 - mx), max(0, y1 - my)
    res = modelo.predict(zona, conf=UMBRAL_M3, device=DISPOSITIVO, verbose=False)[0]
    llantas = []
    if res.keypoints is not None and len(res.boxes):
        for kp, caja, conf in zip(res.keypoints.xy.tolist(), res.boxes.xyxy.tolist(), res.boxes.conf.tolist()):
            bx1, by1, bx2, by2 = caja
            x, y = kp[KPT_CENTRO] if len(kp) > KPT_CENTRO else ((bx1 + bx2) / 2, (by1 + by2) / 2)
            if x <= 0 and y <= 0:  # keypoint no visible: centro de la caja
                x, y = (bx1 + bx2) / 2, (by1 + by2) / 2
            llantas.append([round(x + ox), round(y + oy), round(conf, 3), bx2 - bx1])
    # Llantas cuyos centros se separan menos de media llanta (rueda doble) cuentan como un solo eje.
    llantas.sort(key=lambda p: p[0])
    ejes = []
    for p in llantas:
        if not ejes or p[0] - ejes[-1][0] > 0.5 * max(p[3], ejes[-1][3], 20):
            ejes.append(p)
    return len(ejes), [p[:3] for p in ejes]


def contar_pasajeros(imagen, caja_vehiculo):
    """M4: personas segmentadas cuyo centro cae dentro del vehículo. Devuelve máscaras (no recortes)."""
    res = modelo_pasajeros().predict(imagen, conf=0.35, classes=[0], device=DISPOSITIVO, verbose=False)[0]
    mascaras = []
    for i, caja in enumerate(res.boxes):
        x1, y1, x2, y2 = caja.xyxy[0].tolist()
        cx, cy = (x1 + x2) / 2, (y1 + y2) / 2
        if caja_vehiculo:
            vx1, vy1, vx2, vy2 = caja_vehiculo
            if not (vx1 <= cx <= vx2 and vy1 <= cy <= vy2):
                continue
        if res.masks is not None:
            mascaras.append(res.masks.xy[i])
    return len(mascaras), mascaras


def evidencia(imagen, placa_caja, texto, categoria, caja_vehiculo, poligono, ejes, mascaras_personas, pasajeros):
    """Imagen de evidencia. Las personas se cubren con máscara opaca: no se guardan rostros."""
    img = imagen.copy()
    for m in mascaras_personas:
        if len(m):
            cv2.fillPoly(img, [np.array(m, dtype=np.int32)], (180, 60, 200))
    if poligono:
        capa = img.copy()
        cv2.fillPoly(capa, [np.array(poligono, dtype=np.int32)], (255, 160, 0))
        img = cv2.addWeighted(capa, 0.3, img, 0.7, 0)
    if caja_vehiculo:
        cv2.rectangle(img, tuple(caja_vehiculo[:2]), tuple(caja_vehiculo[2:]), (255, 160, 0), 2)
        cv2.putText(img, f"{categoria} | ejes {len(ejes)} | pasajeros {pasajeros}", (caja_vehiculo[0], max(20, caja_vehiculo[1] - 8)),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.7, (255, 160, 0), 2, cv2.LINE_AA)
    for x, y, _ in ejes:
        cv2.circle(img, (int(x), int(y)), 9, (0, 0, 255), -1)
    if placa_caja:
        x1, y1, x2, y2, _ = placa_caja
        cv2.rectangle(img, (x1, y1), (x2, y2), (0, 200, 0), 3)
        cv2.putText(img, texto or "?", (x1, y2 + 28), cv2.FONT_HERSHEY_SIMPLEX, 0.9, (0, 200, 0), 2, cv2.LINE_AA)
    alto, ancho = img.shape[:2]
    if ancho > 960:
        img = cv2.resize(img, (960, int(alto * 960 / ancho)))
    return base64.b64encode(cv2.imencode(".jpg", img, [cv2.IMWRITE_JPEG_QUALITY, 75])[1]).decode()


# ---------------------------------------------------------------- rutas
@app.get("/salud")
def salud():
    return {
        "ok": True,
        "ocr": OCR_MOTOR,
        "dispositivo": DISPOSITIVO,
        "m2_propio": (MODELOS / "m2_seg.pt").exists(),
        "m3_propio": (MODELOS / "m3_pose.pt").exists(),
        "proveedor": os.getenv("PROVEEDOR", "vast"),
    }


@app.post("/inferir", dependencies=[Depends(verificar_clave)])
async def inferir(imagen: UploadFile = File(...), solo_placa: bool = False):
    inicio = time.perf_counter()
    datos = np.frombuffer(await imagen.read(), np.uint8)
    img = cv2.imdecode(datos, cv2.IMREAD_COLOR)
    if img is None:
        raise HTTPException(status_code=422, detail="La imagen no se pudo leer")

    tiempos = {}
    t = time.perf_counter()
    cajas = detectar_placas(img)
    texto, conf_ocr, caja_placa = "", 0.0, None
    if cajas:
        caja_placa = cajas[0]
        texto, conf_ocr = leer_placa(img, caja_placa)
    tiempos["m1_ms"] = round((time.perf_counter() - t) * 1000)

    respuesta = {
        "placa": normalizar(texto) or None,
        "placa_leida": texto or None,
        "confianza": round(conf_ocr, 3),
        "confianza_deteccion": round(caja_placa[4], 3) if caja_placa else 0.0,
        "placa_detectada": caja_placa is not None,
    }

    if solo_placa:
        respuesta["latencia_ms"] = round((time.perf_counter() - inicio) * 1000)
        respuesta["tiempos"] = tiempos
        return respuesta

    t = time.perf_counter()
    categoria, conf_cat, caja_veh, poligono, m2_propio = clasificar_vehiculo(img)
    tiempos["m2_ms"] = round((time.perf_counter() - t) * 1000)

    t = time.perf_counter()
    ejes, puntos = contar_ejes(img, caja_veh)
    tiempos["m3_ms"] = round((time.perf_counter() - t) * 1000)
    if ejes is None or ejes < 2:
        ejes_final = EJES_POR_DEFECTO.get(categoria, 2) if categoria else None
    else:
        ejes_final = ejes

    t = time.perf_counter()
    pasajeros, mascaras = contar_pasajeros(img, caja_veh)
    tiempos["pasajeros_ms"] = round((time.perf_counter() - t) * 1000)

    respuesta.update({
        "categoria": categoria,
        "confianza_categoria": round(conf_cat, 3),
        "ejes": ejes_final,
        "ejes_detectados_m3": ejes,
        "ruedas": puntos,
        "pasajeros": pasajeros,
        "m2_modelo": "propio" if m2_propio else "coco-respaldo",
        "evidencia_jpg_base64": evidencia(img, caja_placa, respuesta["placa"], categoria, caja_veh, poligono,
                                         puntos, mascaras, pasajeros),
        "tiempos": tiempos,
        "latencia_ms": round((time.perf_counter() - inicio) * 1000),
    })
    return respuesta
