#!/usr/bin/env bash
# Arranque del endpoint en una instancia de Vast.ai (plantilla PyTorch, GPU NVIDIA con CUDA 12.4 o superior).
# Uso, desde la terminal de Jupyter:  bash iniciar_vast.sh
# La clave se escribe al arrancar (la misma que INFERENCE_API_KEY en Render) y no se guarda en disco.
set -e
cd "$(dirname "$0")"
VENV=/workspace/venv-inferencia

if [ -z "${INFERENCE_API_KEY:-}" ]; then
  read -r -s -p "INFERENCE_API_KEY (no se vera al escribir): " INFERENCE_API_KEY; echo
fi
[ -n "$INFERENCE_API_KEY" ] || { echo "La clave no puede quedar vacia."; exit 1; }
export INFERENCE_API_KEY DISPOSITIVO=${DISPOSITIVO:-0} PROVEEDOR=vast

if [ ! -f "$VENV/.listo" ]; then
  PY=""
  for p in python3.12 python3.11 python3.10 python3; do
    if command -v "$p" > /dev/null && "$p" -c 'import sys; sys.exit(not (3, 10) <= sys.version_info[:2] <= (3, 12))'; then PY=$p; break; fi
  done
  [ -n "$PY" ] || { echo "Se necesita Python 3.10, 3.11 o 3.12."; exit 1; }
  apt-get update -qq
  apt-get install -y -qq libgl1 libglib2.0-0 curl > /dev/null
  "$PY" -m venv "$VENV" || { "$PY" -m pip install -q virtualenv && "$PY" -m virtualenv "$VENV"; }
  "$VENV/bin/pip" install -q --upgrade pip
  "$VENV/bin/pip" install torch==2.6.0 torchvision==0.21.0 --index-url https://download.pytorch.org/whl/cu124
  "$VENV/bin/pip" install -r requirements.txt
  touch "$VENV/.listo"
fi

"$VENV/bin/python" diagnostico.py | tee /workspace/diagnostico_vast.txt
"$VENV/bin/python" -c "import torch; assert torch.cuda.is_available(), 'sin GPU'; print('GPU:', torch.cuda.get_device_name(0))"
pkill -f "uvicorn app:app" || true
sleep 1
# Solo escucha dentro de la instancia: se publica con el tunel https del Instance Portal.
nohup "$VENV/bin/uvicorn" app:app --host 127.0.0.1 --port 8000 > /workspace/inferencia.log 2>&1 &

for _ in $(seq 1 60); do
  if curl -sf http://127.0.0.1:8000/salud; then
    echo
    echo "Endpoint listo. Crea el tunel en Instance Portal -> Tunnels: http://localhost:8000"
    exit 0
  fi
  sleep 2
done
echo "No respondio. Ultimas lineas del log:"
tail -n 30 /workspace/inferencia.log
exit 1
