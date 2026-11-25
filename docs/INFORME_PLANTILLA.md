# Informe técnico — Sistema Inteligente de Control de Peaje

> Plantilla: completar los valores marcados con ▢ con los resultados reales y exportar a PDF.

## 1. Arquitectura
- Diagrama (README) y descripción de cada componente: app Flutter → Laravel en Render → FastAPI con GPU en Vast.ai; Neon, Pusher, Gemini, Google OAuth y Arduino.
- Por qué cada decisión: Pusher porque Render gratis no admite un proceso WebSocket; evidencias en la BD porque el disco de Render es efímero; `INFERENCE_URL` desacoplada del proveedor. Se usó Vast.ai en lugar de RunPod porque RunPod rechazó el pago; el docente lo autorizó.

## 2. Datasets
| Modelo | Origen | Imágenes (train/valid/test) | Clases / instancias | Aumentaciones |
|---|---|---|---|---|
| M2 segmentación | Roboflow: ▢ Universe + ▢ fotos propias | ▢/▢/▢ | moto ▢, auto ▢, minibus ▢, camion ▢, bus ▢ | flip H, brillo ±20 %, blur 1 px, ruido 1 % (×3) |
| M3 keypoints | Roboflow Universe: axle (v5) y truck axel (v2, CC BY 4.0), más 9 fotos propias de autos (×4 copias) | 1226/210/32 propias | llanta, 5 puntos | las de los datasets públicos y las de Ultralytics por defecto (flip H incluido) |
| M1 + OCR | Proyecto anterior (detector) + ▢ fotos evaluadas | — | — | — |
| Pasajeros | YOLO11n-seg COCO (persona) + ▢ fotos propias para el conteo | — | persona | — |

M3: los 5 puntos de los datasets públicos se generaron a partir de la caja de cada llanta y los de las fotos propias son pseudoetiquetas del M3 anterior. Las copias aumentadas de una misma foto quedan en el mismo grupo (train o valid). De *truck axel* se usó el recorte de cada camión etiquetado.

Fuentes y licencias de las imágenes públicas: truck axel (CC BY 4.0); axle ▢; M2 ▢

## 3. Entrenamiento y métricas
| Modelo | Base | Épocas | imgsz | mAP50 | mAP50-95 | Otra métrica |
|---|---|---|---|---|---|---|
| M2 (máscara) | yolo11n-seg | 80 | 640 | ▢ | ▢ | caja mAP50 ▢ |
| M3 (pose) | M3 anterior (yolo11s-pose) | 60 | 640 | 0,977 | 0,941 | caja mAP50 0,970 · camiones públicos: conteo exacto 84/105 (80 %), MAE 0,20 |
| M1 + OCR | YOLOv9-t + fast-plate-ocr | — | 384 | — | — | placas exactas ▢/▢ (▢ %) · CER ▢ · EasyOCR ▢ % |
| Pasajeros | yolo11n-seg | — | 640 | ▢ (COCO persona) | ▢ | MAE de conteo ▢ |

**M3 en fotos propias** (32 fotos que no se usaron en este entrenamiento; ejes reales contados a ojo). Aciertos exactos de M3:

| Fotos | M3 inicial | M3 del 23/11 | M3 nuevo, foto completa | M3 nuevo en el endpoint (recorte) |
|---|---|---|---|---|
| Autos (13) | 6 | 9 | 12 | 12 |
| Buses (12) | 0 | 0 | 1 | 4 |
| Camiones (7) | 0 | 0 | 1 | 1 |
| **Total (32)** | **6** | **9** | **14** | **17** |

Si M3 ve menos de 2 ejes, el endpoint usa 2 por defecto: con esa regla el dato final es correcto en 24 de 32.

Limitaciones: el 0,977 se midió en imágenes parecidas a las de entrenamiento; en fotos propias en diagonal el conteo de camiones y buses sigue siendo débil, y no se midió en motos. Son pocas fotos, algunas pudieron usarse en el M3 anterior, y las mismas sirvieron para elegir el recorte.

Incluir: `results.png`, matriz de confusión de M2, ejemplos de predicción.

## 4. Latencia de punta a punta
Medir con la app (el valor `ms` del indicador EN VIVO) y con el JSON (`latencia_inferencia_ms`, `latencia_total_ms`, `tiempos.m1_ms/m2_ms/m3_ms/pasajeros_ms`).
| Tramo | Promedio (ms) |
|---|---|
| Inferencia M1 / M2 / M3 / pasajeros (GPU ▢) | ▢ / ▢ / ▢ / ▢ |
| Endpoint total | ▢ |
| Web (API → inferencia → BD → Pusher) | ▢ |
| App: captura → respuesta | ▢ |
| Aparición en el panel tras la respuesta | < 1 s (Pusher) |

## 5. Tiempo real
Pusher Channels, canal privado `private-panel` autorizado por la sesión de Laravel (`/broadcasting/auth`). Eventos `paso.registrado` (paso + contadores) y `alerta.creada`. Se emiten con `ShouldBroadcastNow` (sin cola).

## 6. Seguridad
Google OAuth (Socialite) en la web; en la app, el ID token se valida con `tokeninfo` (aud, iss, email_verified) y se emite un token Sanctum de 7 días. Lista blanca y roles en la tabla `usuarios`. La inferencia se protege con `X-API-Key`. No hay secretos en el repositorio.

## 7. Puntos adicionales
- Entornos: `requirements.txt` con versiones fijadas + salidas de `diagnostico.py` (Vast.ai, Colab).
- Endpoint GPU en vivo: Vast.ai, RTX 3090, URL ▢ (aceptado por el docente en lugar de RunPod).
- Pasajeros: campo `pasos.pasajeros`, JSON `pasajeros`, columna en el panel, evidencia con máscara.
- Hardware: Arduino UNO por USB OTG con LEDs y buzzer (fotos del circuito).

## 8. Problemas encontrados y soluciones
| Problema | Solución |
|---|---|
| Varios frames del mismo vehículo generaban pasos repetidos | Ventana anti-duplicado de 60 s por placa y caseta |
| Disco efímero de Render | Evidencias en la tabla `evidencias` |
| Errores típicos del OCR (O/0, B/8…) | Sugerencias por sustitución y distancia de edición 1 |
| Ruedas dobles contadas como dos ejes | Fusión de keypoints cuyos centros se separan menos de media llanta en x |
| M3 casi no veía llantas de camiones y buses | Reentrenamiento con dos datasets públicos y análisis sobre el recorte del vehículo |
| RunPod rechazó las tarjetas y Colab no daba GPU | Vast.ai para entrenar y para el endpoint |

## 9. Conclusiones
▢
