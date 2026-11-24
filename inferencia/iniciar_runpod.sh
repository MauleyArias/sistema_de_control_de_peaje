#!/usr/bin/env bash
# Arranque en un Pod de RunPod (plantilla PyTorch) sin Docker propio.
# 1) Subir esta carpeta a /workspace/inferencia (git clone del repo).  2) Copiar m2_seg.pt y m3_pose.pt a modelos/.
# 3) Definir INFERENCE_API_KEY en las variables de entorno del Pod.  4) bash iniciar_runpod.sh
set -e
cd "$(dirname "$0")"
python -m venv /workspace/venv-inferencia
source /workspace/venv-inferencia/bin/activate
pip install --upgrade pip
pip install -r requirements.txt
python diagnostico.py | tee diagnostico_runpod.txt
export DISPOSITIVO=${DISPOSITIVO:-0}
nohup uvicorn app:app --host 0.0.0.0 --port 8000 > /workspace/inferencia.log 2>&1 &
echo "Endpoint: https://${RUNPOD_POD_ID}-8000.proxy.runpod.net  (log: /workspace/inferencia.log)"
