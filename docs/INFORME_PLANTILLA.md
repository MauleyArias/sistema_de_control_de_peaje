# Informe técnico — Sistema Inteligente de Control de Peaje

**Autora:** Shirley Andrea Arias (entrega individual)


## 1. Arquitectura
- Diagrama (README) y descripción de cada componente: app Flutter → Laravel en Render → FastAPI con GPU en Vast.ai; Neon, Pusher, Gemini, Google OAuth y Arduino.
- Por qué cada decisión: Pusher porque Render gratis no admite un proceso WebSocket; evidencias en la BD porque el disco de Render es efímero; `INFERENCE_URL` desacoplada del proveedor. Se usó Vast.ai en lugar de RunPod porque RunPod rechazó el pago; el docente lo autorizó.

## 2. Datasets
| Modelo | Origen | Imágenes (train/valid/test) | Clases / instancias | Aumentaciones |
|---|---|---|---|---|
| M2 segmentación | Roboflow, proyecto Peaje - Vehículos, versión 2 | 81 instancias de entrenamiento | moto 9, auto 17, minibus 14, camion 30, bus 11 | las de Ultralytics por defecto |
| M3 keypoints | Roboflow Universe: axle (v5) y truck axel (v2, CC BY 4.0), más 9 fotos propias de autos (×4 copias) | 1226/210/32 propias | llanta, 5 puntos | las de los datasets públicos y las de Ultralytics por defecto (flip H incluido) |
| M1 + OCR | Proyecto anterior (detector) + 48 fotos evaluadas (28 propias con placas bolivianas y 20 extranjeras) | — | — | — |
| Pasajeros | YOLO11n-seg COCO (persona) | sin entrenamiento propio | persona | — |

M3: los 5 puntos de los datasets públicos se generaron a partir de la caja de cada llanta y los de las fotos propias son pseudoetiquetas del M3 anterior. Las copias aumentadas de una misma foto quedan en el mismo grupo (train o valid). De *truck axel* se usó el recorte de cada camión etiquetado.

Fuentes y licencias de las imágenes públicas: truck axel (CC BY 4.0); axle y M2: ver la página de cada dataset.

## 3. Entrenamiento y métricas
| Modelo | Base | Épocas | imgsz | mAP50 | mAP50-95 | Otra métrica |
|---|---|---|---|---|---|---|
| M2 (máscara) | yolo11s-seg | 60 | 640 | 0,387 | 0,208 | caja mAP50 0,398 (última época) |
| M3 (pose) | M3 anterior (yolo11s-pose) | 60 | 640 | 0,977 | 0,941 | caja mAP50 0,970 · camiones públicos: conteo exacto 84/105 (80 %), MAE 0,20 |
| M1 + OCR | YOLOv9-t + fast-plate-ocr | — | 384 | — | — | bolivianas propias: 25/28 exactas (89 %), CER 0,046 (9/195) · extranjeras: 16/19 (84 %), CER 0,110 (13/118) |
| Pasajeros | yolo11n-seg | — | 640 | — | — | sin evaluación propia; puede contar peatones detrás del vehículo |

**M3 en fotos propias** (32 fotos que no se usaron en este entrenamiento; ejes reales contados a ojo). Aciertos exactos de M3:

| Fotos | M3 inicial | M3 del 23/11 | M3 nuevo, foto completa | M3 nuevo en el endpoint (recorte) |
|---|---|---|---|---|
| Autos (13) | 6 | 9 | 12 | 13 |
| Buses (12) | 0 | 0 | 1 | 4 |
| Camiones (7) | 0 | 0 | 1 | 2 |
| **Total (32)** | **6** | **9** | **14** | **19** |

Si M3 ve menos de 2 ejes, el endpoint usa 2 por defecto: con esa regla el dato final es correcto en 25 de 32.

Limitaciones: el 0,977 se midió en imágenes parecidas a las de entrenamiento; en fotos propias en diagonal el conteo de camiones y buses sigue siendo débil, y no se midió en motos. Son pocas fotos, algunas pudieron usarse en el M3 anterior, y las mismas sirvieron para elegir el recorte y el umbral de M2 (0.5).

**Categoría (M2 + respaldo COCO)** en 102 fotos propias, con umbral 0.5: 82 aciertos (auto 17/36, camion 23/24, moto 21/21, bus 21/21). M2 decide en 30 y acierta 28; en las demás responde COCO. Los errores son sobre todo SUV y pickups clasificados como camión. Algunas fotos pudieron usarse para entrenar M2.

**Prueba con 28 fotos propias nuevas** (no usadas en el entrenamiento de M3 ni para elegir umbrales; tipo anotado a ojo): 18 aciertos de categoría. M2 decidió en 1 y falló; el resto lo resolvió COCO, que no tiene la clase minibús (0 de 6).

**OCR, fotos propias:** 28 fotos tomadas en la calle, varias de noche. Fallos: en 1 el detector eligió una placa decorativa y en 2 el OCR confundió un carácter (4→A, D→Q).

**OCR, fotos extranjeras:** 20 fotos; una no es legible a simple vista y se excluyó. Los textos de referencia se leyeron a ojo. Fallos: en 2 fotos el detector eligió otra zona (un faro y unas calcomanías) y en 1 el OCR leyó mal la placa.


## 4. Latencia de punta a punta
| Tramo | Promedio (ms) |
|---|---|
| Desglose de una foto (RTX 3090; M1 en CPU) M1 / M2 / M3 / pasajeros | 99 / 195 / 45 / 24 |
| Endpoint total (5 fotos) | 470 (285 a 704) |
| `/salud` vista desde Render | 395 |
| App: captura → respuesta | se observa en el indicador EN VIVO de la app |

## 5. Tiempo real
Pusher Channels, canal privado `private-panel` autorizado por la sesión de Laravel (`/broadcasting/auth`). Eventos `paso.registrado` (paso + contadores) y `alerta.creada`. Se emiten con `ShouldBroadcastNow` (sin cola).

## 6. Seguridad
Google OAuth (Socialite) en la web; en la app, el ID token se valida con `tokeninfo` (aud, iss, email_verified) y se emite un token Sanctum de 7 días. Lista blanca y roles en la tabla `usuarios`. La inferencia se protege con `X-API-Key`. No hay secretos en el repositorio.

## 7. Puntos adicionales
- Entornos: `requirements.txt` con versiones fijadas + salidas de `diagnostico.py` (Vast.ai, Colab).
- Endpoint GPU en vivo: Vast.ai, RTX 3090, URL `https://instrumental-democrat-folk-tables.trycloudflare.com/salud` (aceptado por el docente en lugar de RunPod).
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
El sistema funciona de punta a punta en la nube. La lectura de placas bolivianas es lo más sólido (25/28) y el reentrenamiento de M3 elevó los aciertos de ejes de 6 a 19 de 32. Lo principal por mejorar es el dataset de M2 (minibuses, SUV y fotos nocturnas) y evaluar el conteo de pasajeros.
