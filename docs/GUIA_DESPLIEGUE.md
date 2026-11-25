# Guía de despliegue paso a paso

Orden recomendado (cada paso deja algo que necesita el siguiente). Anota cada valor en un bloc **local** (nunca en el repositorio):
`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `DB_URL`, `PUSHER_*`, `GEMINI_API_KEY`, `INFERENCE_API_KEY`, `INFERENCE_URL`, `APP_KEY`.

> ⚠️ Nada puede correr en tu PC durante la demo ni en la revisión. Tu PC solo sirve para programar y compilar el APK.

---

## 0. Repositorio en GitHub (10 min)

1. En github.com, **New repository** → ya creado: https://github.com/MauleyArias/sistema_de_control_de_peaje. No marques "Add README".
2. En PowerShell, dentro de `D:\UNIVERSIDAD\IA\peaje`:
   ```powershell
   git add .
   git commit -m "Sistema de control de peaje"
   git remote add origin https://github.com/MauleyArias/sistema_de_control_de_peaje.git
   git push -u origin main
   ```
3. Antes de cada push, revisa que `git status` **no** muestre ningún `.env` (−10 puntos si se sube un secreto).

## 1. Google Cloud: OAuth 2.0 (20 min)

1. https://console.cloud.google.com → **Nuevo proyecto** `peaje-ia`.
2. **APIs y servicios → Pantalla de consentimiento de OAuth** (Google Auth Platform):
   - Tipo **Externo**, nombre `Peaje IA`, tu correo de soporte.
   - Alcances: `openid`, `email`, `profile`.
   - **Usuarios de prueba**: agrega **tu Gmail**, **doc.alvaro.pinto.ma@unifranz.edu.bo** y la cuenta que usarás para mostrar el *rechazo*. Mientras la app esté "En prueba", **solo esos usuarios** pueden completar el inicio de sesión con Google. (Otra opción: *Publicar app*. Con solo los alcances básicos no requiere verificación).
3. **Credenciales → Crear credenciales → ID de cliente de OAuth → Aplicación web**:
   - Orígenes autorizados: `https://peaje-ia.onrender.com`
   - URI de redirección autorizados: `https://peaje-ia.onrender.com/auth/google/callback`
   - Copia el **ID de cliente** → `GOOGLE_CLIENT_ID` y `GOOGLE_ALLOWED_AUDIENCES`; el **secreto** → `GOOGLE_CLIENT_SECRET`.
   - (El nombre `peaje-ia.onrender.com` lo eliges en Render, paso 6. Si sale otro, actualiza estas dos URL).
4. **Crear credenciales → ID de cliente de OAuth → Android**:
   - Nombre del paquete: `bo.edu.unifranz.peaje_movil`
   - Huella SHA-1 de la llave con la que se firma el APK (el APK *release* del proyecto se firma con la llave *debug*):
     ```powershell
     keytool -list -v -keystore "$env:USERPROFILE\.android\debug.keystore" -alias androiddebugkey -storepass android -keypass android
     ```
     (`keytool` viene con Java: `C:\Program Files\Microsoft\jdk-17...\bin\keytool.exe`).
   - Este cliente no se usa en el código: Google lo exige para que el celular pueda emitir el ID token. La app usa el **ID de cliente web** como `serverClientId`, así que el backend recibe tokens con `aud` = cliente web.

Para el **rechazo** de una cuenta no autorizada en el video, usa una cuenta que esté entre los usuarios de prueba de Google pero **no** en la tabla `usuarios`. Verás la pantalla "Acceso denegado" en la web y el mensaje ⛔ en la app.

## 2. Datasets en Roboflow (lo que más tiempo toma)

### M2 · Segmentación (tipo de vehículo)
1. https://app.roboflow.com → **New Project** → *Instance Segmentation* → `peaje-vehiculos-seg`.
2. Clases: `moto`, `auto`, `minibus`, `camion`, `bus`.
3. (Opcional) En Roboflow Universe, busca "vehicle segmentation" o "vehicle type", haz **Fork** o descarga y sube algunas imágenes. Revisa la licencia y anótala en el informe.
4. **Sube tus fotos propias** (carpetas `moto`, `bus`, `camion`, `autos_de_costado`). Apunta a unas 40 o más por clase (minibuses: fotos de la calle).
5. Etiqueta con **Smart Polygon** (un clic sobre el vehículo) y corrige.
6. **Generate → Version**: Resize 640×640 (Stretch), Augmentation ×3: Flip horizontal, Brightness ±20 %, Blur 1 px, Noise 1 %. Split 70/20/10.
7. Copia `workspace`, `project` y `version` en el notebook `M2_segmentacion_vehiculos.ipynb`.

### M3 · Keypoints de ruedas (ejes)
1. **New Project** → *Keypoint Detection* → `peaje-ruedas-keypoints`.
2. Esqueleto (Skeleton): **un solo punto** llamado `centro`. Clase: `rueda`.
3. Sube las fotos **de costado** (autos, camiones de 2 a 6 ejes, buses, motos). Unas 60 o más.
4. En cada rueda visible: dibuja la caja y coloca el punto en el centro del aro. Las ruedas dobles de un mismo eje van como una sola rueda.
5. Versión: Resize 640, Brightness ±20 %, Blur 1 px, Rotation ±5°. **Sin flip vertical**.
6. Copia los datos en `M3_keypoints_ruedas.ipynb`.

## 3. Entrenamiento en Colab (1 a 2 h, mayormente esperando)

1. Abre cada notebook de `/entrenamiento` en Colab (*Archivo → Subir notebook*, o desde GitHub).
2. *Entorno de ejecución → Cambiar tipo → GPU T4*.
3. Icono de llave (**Secretos**) → nuevo secreto `ROBOFLOW_API_KEY` (de Roboflow: *Settings → API Keys*). Activa el acceso del notebook.
4. Ejecuta todo. Al final quedan en Drive `peaje_evidencias/m2_seg.pt`, `m3_pose.pt`, gráficas (`results.png`, matrices de confusión) y las métricas impresas. Haz **capturas** de las métricas para el informe.
5. `M1_placas_ocr_validacion.ipynb`: sube las 20 fotos de `PLACAS_H1900-2130_8405213/INFERENCIA` (y fotos propias) con su `referencias.txt` **completo** (`01.jpg=PLACA_REAL`). Te da la precisión exacta y el CER del OCR.
6. `Pasajeros_segmentacion.ipynb`: métricas del modelo de personas y error de conteo con tus fotos.
7. Guarda la salida de la celda de **diagnóstico** de cada notebook (punto adicional de entornos).

## 4. Endpoint de inferencia en Vast.ai (30 min) — punto adicional

1. https://cloud.vast.ai → **Templates → PyTorch (Vast)**. Disco: **40 GB** (no se puede cambiar después). *On-Demand*, 1 GPU (RTX 3090 o similar; no serie 50), CUDA 12.4 o más.
2. **Instances → Open → Jupyter Terminal**:
   ```bash
   cd /workspace
   git clone https://github.com/MauleyArias/sistema_de_control_de_peaje.git peaje
   bash peaje/inferencia/iniciar_vast.sh     # pide INFERENCE_API_KEY (la misma de Render)
   ```
3. **Instance Portal → Tunnels**: escribir `http://localhost:8000` → **Create New Tunnel**.
4. Abrir `https://….trycloudflare.com/salud`: debe mostrar `"ok": true`, `"m2_propio": true`, `"m3_propio": true` y `"proveedor": "vast"`.
5. Esa URL, sin `/salud`, va en `INFERENCE_URL` de Render. Si cambia el túnel, hay que actualizarla.
6. La instancia cobra mientras está encendida y debe estarlo en la revisión. **Destroy** borra todo.

### Alternativa: RunPod

1. https://runpod.io → **Pods → Deploy**. GPU barata (RTX A4000, RTX 3090 o A5000 en *Community Cloud*, unos 0,2 a 0,4 USD/h).
2. Plantilla: **RunPod PyTorch 2.4** (o similar con CUDA 12). Disco del contenedor: 20 GB; volumen: 20 GB.
3. **Edit Template → Expose HTTP Ports**: `8000`. **Environment Variables**: `INFERENCE_API_KEY` = una clave larga inventada (la misma irá en Render).
4. Deploy → **Connect → Web Terminal** (o Jupyter Lab):
   ```bash
   cd /workspace
   git clone https://github.com/MauleyArias/sistema_de_control_de_peaje.git
   cd sistema_de_control_de_peaje/inferencia
   # subir m2_seg.pt y m3_pose.pt a modelos/ (Jupyter: arrastrar el archivo; o gdown desde Drive)
   bash iniciar_runpod.sh
   ```
5. La URL pública es `https://<POD_ID>-8000.proxy.runpod.net`. Prueba `…/salud` en el navegador: debe mostrar `"ok": true`, `"m2_propio": true` y `"m3_propio": true`.
6. Esa URL va en `INFERENCE_URL`.
7. **Costo**: el Pod cobra mientras está encendido. Tiene que estar **encendido en la fecha de revisión**. Calcula: 0,25 USD/h × 72 h ≈ 18 USD. Si reinicias el Pod, vuelve a ejecutar `bash iniciar_runpod.sh` (los archivos de `/workspace` se conservan).
8. Respaldo: `entrenamiento/Inferencia_en_Colab.ipynb` levanta el mismo endpoint en Colab. Solo cambias `INFERENCE_URL` (pero no da el punto adicional de RunPod).

## 5. Base de datos, tiempo real y LLM (15 min)

- **Neon (PostgreSQL gratis, no expira)**: https://neon.tech → New project → copia la *connection string* (`postgresql://…?sslmode=require`) → `DB_URL`.
- **Pusher**: https://pusher.com → Channels → *Create app* (cluster `us2` o `sa1`) → *App Keys*: `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER`.
- **Gemini**: https://aistudio.google.com/apikey → *Create API key* → `GEMINI_API_KEY`.
- **APP_KEY** de Laravel (en tu PC):
  ```powershell
  cd D:\UNIVERSIDAD\IA\peaje\web; php artisan key:generate --show
  ```

## 6. Aplicación web en Render (20 min)

1. https://render.com → iniciar sesión con GitHub → **New → Blueprint** → elige el repositorio `sistema_de_control_de_peaje` (Render lee `render.yaml`).
2. Nombre del servicio: `peaje-ia` → subdominio `https://peaje-ia.onrender.com` (si ya está ocupado, elige otro y actualiza Google Cloud).
3. Completa las variables marcadas (todas las de `web/.env.example`):
   `APP_KEY`, `APP_URL`, `DB_URL`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI=https://peaje-ia.onrender.com/auth/google/callback`, `GOOGLE_ALLOWED_AUDIENCES` (= client ID web), `ADMIN_EMAILS` (tu Gmail), `PUSHER_*`, `INFERENCE_URL`, `INFERENCE_API_KEY`, `GEMINI_API_KEY`.
4. **Apply**. El primer build tarda unos 5 a 8 minutos. Al arrancar, el contenedor migra la BD y crea la lista blanca (docente + tu correo como admin), las 2 casetas, las tarifas y los 22 vehículos de prueba.
5. Abre la URL → *Iniciar sesión con Google* → Panel. Revisa **Estado**: inferencia "En línea" y tiempo real "connected".
6. El plan gratis **se duerme tras 15 min sin tráfico** (despierta en unos 50 s). Antes de grabar el video, y el día de la revisión, abre la página un minuto antes.

## 7. App móvil (APK)

En esta PC el disco C: está lleno: compila siempre con la caché de Gradle y los temporales en D: (en la misma ventana de PowerShell):
```powershell
$env:GRADLE_USER_HOME='D:\dev-cache\gradle'; $env:TEMP='D:\dev-cache\tmp'; $env:TMP='D:\dev-cache\tmp'; $env:JAVA_TOOL_OPTIONS='-Djava.io.tmpdir=D:\dev-cache\tmp'
```
```powershell
cd D:\UNIVERSIDAD\IA\peaje\movil
flutter build apk --release --dart-define=API_URL=https://peaje-ia.onrender.com --dart-define=GOOGLE_WEB_CLIENT_ID=TU_CLIENT_ID_WEB.apps.googleusercontent.com
```
El APK queda en `movil\build\app\outputs\flutter-apk\app-release.apk` → súbelo a la carpeta de Drive y cópialo al celular.
(Si es la primera vez: `flutter doctor --android-licenses` y acepta todo).

## 8. Arduino UNO (punto adicional)

1. Arma el circuito (ver comentario en `hardware/alerta_peaje/alerta_peaje.ino`): LED verde en D8, amarillo en D9, rojo en D10 (cada uno con resistencia de 220 Ω) y buzzer activo en D11.
2. Carga el sketch con el Arduino IDE.
3. Conecta el Arduino al celular con un **cable OTG** (USB-C/micro-USB a USB-A hembra + el cable del Arduino). Android pregunta si abrir *Peaje IA*: acepta y concede el permiso USB. La etiqueta arriba a la derecha de la cámara dirá "Arduino: conectado".
4. Señales: amarillo parpadeando = inferencia en curso · verde + pitido = cobrado · **rojo + buzzer = alerta** (no registrada o sin saldo).

## 9. Checklist del video (8 a 12 min)

- [ ] Presentación y arquitectura (máx. 1 min, usa el diagrama del README).
- [ ] Login con Google en la **web** y en la **app**, y **rechazo** de una cuenta no autorizada (en las dos).
- [ ] App en **modo Caseta** con video real apuntando a un vehículo: placa, categoría, ejes, tarifa y estado.
- [ ] **Pantalla dividida**: el panel web recibe el paso sin recargar (celular grabado al lado del monitor, o scrcpy).
- [ ] Agente con los **4 estados**: `2580BOS` (con paso), `3301SCZ` (sin paso), `9090XYZ` (identificada no registrada), `1111AAA` (no existe), `258OBOS` (sugerencia), al menos una consulta **por imagen** y una foto sin placa.
- [ ] **Alerta en tiempo real** con una placa no registrada (o un vehículo sin saldo, como `1500PND`) y el Arduino con rojo + buzzer.
- [ ] Consola de **Vast.ai** con la instancia en ejecución y una petición en vivo (`/salud` o el log `tail -f /workspace/inferencia.log`).
- [ ] Caso con **varios pasajeros** (el panel muestra el número).
- [ ] Conclusiones y métricas (mAP de M2 y M3, precisión del OCR, error de ejes, latencia).

> Para la demo real, registra en **Vehículos** las placas de los autos que vas a filmar (el tuyo, el de tu familia) con saldo. Para la alerta, filma uno **sin** registrar.
