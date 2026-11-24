"""Diagnóstico de entorno (punto adicional): ejecutar en cada entorno de la nube y guardar la salida.

python diagnostico.py > diagnostico_<entorno>.txt
"""
import importlib
import platform
import sys
from datetime import datetime, timezone

print("fecha_utc:", datetime.now(timezone.utc).isoformat())
print("python:", sys.version.split()[0], "|", sys.executable)
print("sistema:", platform.platform())
for paquete in ("torch", "torchvision", "ultralytics", "onnxruntime", "fast_plate_ocr", "easyocr", "cv2", "numpy", "fastapi"):
    try:
        modulo = importlib.import_module(paquete)
        print(f"{paquete}:", getattr(modulo, "__version__", "instalado"))
    except Exception as error:  # noqa: BLE001
        print(f"{paquete}: NO ({error.__class__.__name__})")
try:
    import torch
    print("cuda_disponible:", torch.cuda.is_available())
    print("cuda_version:", torch.version.cuda)
    if torch.cuda.is_available():
        print("gpu:", torch.cuda.get_device_name(0))
except Exception:  # noqa: BLE001
    pass
try:
    import onnxruntime as ort
    print("onnx_providers:", ort.get_available_providers())
except Exception:  # noqa: BLE001
    pass
