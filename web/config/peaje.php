<?php

return [
    // Endpoint de inferencia (Vast.ai, RunPod o Colab). Cambiar de proveedor = cambiar esta variable.
    'inference_url' => env('INFERENCE_URL', ''),
    'inference_key' => env('INFERENCE_API_KEY', ''),
    'inference_timeout' => (int) env('INFERENCE_TIMEOUT', 40),

    // Lectura mínima del OCR para registrar un paso y ventana anti-duplicados entre frames.
    'confianza_minima' => (float) env('PEAJE_CONFIANZA_MINIMA', 0.5),
    'ventana_duplicado_s' => (int) env('PEAJE_VENTANA_DUPLICADO', 60),

    // Agente conversacional (Google Gemini, function calling).
    'gemini_key' => env('GEMINI_API_KEY'),
    'gemini_model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),

    // Google OAuth: IDs de cliente aceptados como "aud" del ID token (web + Android).
    'google_client_ids' => array_filter(array_map('trim', explode(',', (string) env('GOOGLE_ALLOWED_AUDIENCES', env('GOOGLE_CLIENT_ID', ''))))),

    // Lista blanca inicial (el seeder las crea). El docente siempre es administrador.
    'admin_emails' => array_filter(array_map('trim', explode(',', 'doc.alvaro.pinto.ma@unifranz.edu.bo,'.env('ADMIN_EMAILS', '')))),
    'operador_emails' => array_filter(array_map('trim', explode(',', (string) env('OPERADOR_EMAILS', '')))),

    // Pusher: credenciales públicas que necesita el navegador.
    'pusher_key' => env('PUSHER_APP_KEY'),
    'pusher_cluster' => env('PUSHER_APP_CLUSTER', 'us2'),
];
