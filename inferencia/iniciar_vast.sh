#!/usr/bin/env bash
# Arranque del endpoint en una instancia de Vast.ai (plantilla PyTorch, GPU NVIDIA).
# Uso, desde la terminal de Jupyter:  bash iniciar_vast.sh
# Usa el PyTorch de la plantilla (/venv/main). La clave se escribe al arrancar (la misma que
# INFERENCE_API_KEY en Render) y no se guarda en disco.
set -e
cd "$(dirname "$0")"
PY=${PY:-/venv/main/bin/python}

if [ -z "${INFERENCE_API_KEY:-}" ]; then
  read -r -s -p "INFERENCE_API_KEY (no se vera al escribir): " INFERENCE_API_KEY; echo
fi
[ -n "$INFERENCE_API_KEY" ] || { echo "La clave no puede quedar vacia."; exit 1; }
export INFERENCE_API_KEY DISPOSITIVO=${DISPOSITIVO:-0} PROVEEDOR=vast

apt-get update -qq
apt-get install -y -qq libgl1 libglib2.0-0 curl > /dev/null
"$PY" -m pip install -r requirements_vast.txt
"$PY" diagnostico.py | tee /workspace/diagnostico_vast.txt
"$PY" -c "import torch; assert torch.cuda.is_available(), 'sin GPU'"

pkill -f "uvicorn app:app" || true
sleep 2
# Solo escucha dentro de la instancia: se publica con el tunel https del Instance Portal.
nohup "$PY" -m uvicorn app:app --host 127.0.0.1 --port 8000 > /workspace/inferencia.log 2>&1 &

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
