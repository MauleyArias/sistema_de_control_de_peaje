# Informe técnico — Sistema Inteligente de Control de Peaje

> Plantilla: completar los valores marcados con ▢ con los resultados reales y exportar a PDF.

## 1. Arquitectura
- Diagrama (README) y descripción de cada componente: app Flutter → Laravel en Render → FastAPI en RunPod; Neon, Pusher, Gemini, Google OAuth y Arduino.
- Por qué cada decisión: Pusher porque Render gratis no admite un proceso WebSocket; evidencias en la BD porque el disco de Render es efímero; `INFERENCE_URL` desacoplada (RunPod ↔ Colab).

## 2. Datasets
| Modelo | Origen | Imágenes (train/valid/test) | Clases / instancias | Aumentaciones |
|---|---|---|---|---|
| M2 segmentación | Roboflow: ▢ Universe + ▢ fotos propias | ▢/▢/▢ | moto ▢, auto ▢, minibus ▢, camion ▢, bus ▢ | flip H, brillo ±20 %, blur 1 px, ruido 1 % (×3) |
| M3 keypoints | Roboflow: ▢ fotos propias de costado | ▢/▢/▢ | rueda ▢ | brillo ±20 %, blur 1 px, rotación ±5° (×3) |
| M1 + OCR | Proyecto anterior (detector) + ▢ fotos evaluadas | — | — | — |
| Pasajeros | YOLO11n-seg COCO (persona) + ▢ fotos propias para el conteo | — | persona | — |

Fuentes y licencias de las imágenes públicas: ▢

## 3. Entrenamiento y métricas
| Modelo | Base | Épocas | imgsz | mAP50 | mAP50-95 | Otra métrica |
|---|---|---|---|---|---|---|
| M2 (máscara) | yolo11n-seg | 80 | 640 | ▢ | ▢ | caja mAP50 ▢ |
| M3 (pose) | yolo11n-pose | 100 | 640 | ▢ | ▢ | conteo exacto de ejes ▢ % · MAE ▢ |
| M1 + OCR | YOLOv9-t + fast-plate-ocr | — | 384 | — | — | placas exactas ▢/▢ (▢ %) · CER ▢ · EasyOCR ▢ % |
| Pasajeros | yolo11n-seg | — | 640 | ▢ (COCO persona) | ▢ | MAE de conteo ▢ |

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
- Entornos: `requirements.txt` con versiones fijadas + salidas de `diagnostico.py` (RunPod, Colab).
- RunPod en vivo: Pod ▢ (GPU ▢), URL ▢.
- Pasajeros: campo `pasos.pasajeros`, JSON `pasajeros`, columna en el panel, evidencia con máscara.
- Hardware: Arduino UNO por USB OTG con LEDs y buzzer (fotos del circuito).

## 8. Problemas encontrados y soluciones
| Problema | Solución |
|---|---|
| Varios frames del mismo vehículo generaban pasos repetidos | Ventana anti-duplicado de 60 s por placa y caseta |
| Disco efímero de Render | Evidencias en la tabla `evidencias` |
| Errores típicos del OCR (O/0, B/8…) | Sugerencias por sustitución y distancia de edición 1 |
| Ruedas dobles contadas como dos ejes | Fusión de keypoints a menos de 25 px en x |
| ▢ | ▢ |

## 9. Conclusiones
▢
