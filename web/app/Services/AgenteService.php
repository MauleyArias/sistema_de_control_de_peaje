<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Agente conversacional con function calling (Google Gemini).
 * El LLM solo redacta: los datos salen de las tools, que consultan la BD.
 * Si Gemini no está disponible, responde con una plantilla determinista usando los mismos datos.
 */
class AgenteService
{
    private const INSTRUCCIONES = <<<'TXT'
Eres el asistente del sistema de control de peaje. Respondes en español, en 2 a 4 frases.
Reglas obligatorias:
1. Para cualquier consulta sobre una placa DEBES llamar a la herramienta consultar_placa. Nunca respondas datos de una placa sin llamarla.
2. Usa SOLO los datos devueltos por las herramientas. No inventes fechas, montos, casetas, propietarios ni estados.
3. Indica siempre uno de los cuatro estados con estas palabras exactas:
   - "Registrada y con paso" (incluye fecha, caseta, categoría y monto del último paso)
   - "Registrada sin paso"
   - "Identificada no registrada" (menciona que es una posible evasión)
   - "No existe"
4. Si la herramienta devuelve sugerencias, pregunta "¿Quisiste decir …?" con esas placas (pueden ser errores de OCR como O/0, I/1, B/8, S/5).
5. Si el formato de la placa no es válido, dilo y pide la placa de nuevo.
6. Si el usuario pregunta algo ajeno al peaje, indica amablemente que solo atiendes consultas de placas.
TXT;

    private const ETIQUETAS = [
        PlacaService::REGISTRADA_CON_PASO => 'Registrada y con paso',
        PlacaService::REGISTRADA_SIN_PASO => 'Registrada sin paso',
        PlacaService::IDENTIFICADA_NO_REGISTRADA => 'Identificada no registrada',
        PlacaService::NO_EXISTE => 'No existe',
    ];

    /**
     * @param  array|null  $lecturaImagen  resultado de M1 + OCR cuando la consulta llega como imagen
     */
    public function responder(string $mensaje, ?array $lecturaImagen = null, array $historial = []): array
    {
        if ($lecturaImagen !== null) {
            $placaImg = PlacaService::normalizar($lecturaImagen['placa'] ?? '');
            if ($placaImg === '' || ! PlacaService::formatoValido($placaImg)) {
                return [
                    'respuesta' => 'No pude leer una placa en la imagen. Por favor envía otra foto donde la placa se vea completa, de frente y con buena luz.',
                    'estado' => null, 'placa' => null, 'datos' => null, 'motor' => 'reglas',
                    'lectura_imagen' => $lecturaImagen,
                ];
            }
            $confianza = round(((float) ($lecturaImagen['confianza'] ?? 0)) * 100);
            $mensaje = trim("[Imagen recibida: el OCR leyó la placa {$placaImg} con {$confianza}% de confianza] ".$mensaje);
        }

        $consultas = [];
        $texto = null;
        $motor = 'reglas';
        if (config('peaje.gemini_key')) {
            try {
                $texto = $this->conGemini($mensaje, $historial, $consultas);
                $motor = 'gemini:'.config('peaje.gemini_model');
            } catch (\Throwable $e) {
                Log::warning('Agente: Gemini falló, se usa la plantilla', ['error' => $e->getMessage()]);
            }
        }

        // Respaldo determinista (o si el LLM no llamó a la herramienta).
        if (empty($consultas)) {
            $placa = $this->extraerPlaca($mensaje);
            if ($placa !== null) {
                $consultas[] = PlacaService::consultar($placa);
            }
            $texto = null;
        }
        $ultimo = end($consultas) ?: null;
        if ($texto === null || trim($texto) === '') {
            $motor = 'reglas';
            $texto = $ultimo ? $this->plantilla($ultimo)
                : 'Puedo informarte el estado de una placa. Escríbela (por ejemplo 1234ABC) o envía una foto de la placa.';
        }

        return [
            'respuesta' => trim($texto),
            'estado' => $ultimo['estado'] ?? null,
            'estado_texto' => isset($ultimo['estado']) ? self::ETIQUETAS[$ultimo['estado']] : null,
            'placa' => $ultimo['placa'] ?? null,
            'datos' => $ultimo,
            'motor' => $motor,
            'lectura_imagen' => $lecturaImagen,
        ];
    }

    private function conGemini(string $mensaje, array $historial, array &$consultas): string
    {
        $contenidos = [];
        foreach (array_slice($historial, -6) as $h) {
            if (! empty($h['texto']) && in_array($h['rol'] ?? '', ['user', 'model'], true)) {
                $contenidos[] = ['role' => $h['rol'], 'parts' => [['text' => (string) $h['texto']]]];
            }
        }
        $contenidos[] = ['role' => 'user', 'parts' => [['text' => $mensaje]]];

        $herramientas = [['functionDeclarations' => [[
            'name' => 'consultar_placa',
            'description' => 'Consulta en la base de datos del peaje el estado de una placa: si está registrada, sus pasos, último paso y sugerencias por errores de OCR. La placa se normaliza (mayúsculas, sin guiones ni espacios).',
            'parameters' => [
                'type' => 'object',
                'properties' => ['placa' => ['type' => 'string', 'description' => 'Placa tal como la escribió el usuario o la leyó el OCR']],
                'required' => ['placa'],
            ],
        ]]]];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.config('peaje.gemini_model').':generateContent';
        for ($vuelta = 0; $vuelta < 4; $vuelta++) {
            $r = Http::timeout(30)->withHeaders(['x-goog-api-key' => config('peaje.gemini_key')])->post($url, [
                'systemInstruction' => ['parts' => [['text' => self::INSTRUCCIONES]]],
                'contents' => $contenidos,
                'tools' => $herramientas,
                'generationConfig' => ['temperature' => 0.1],
            ])->throw()->json();

            $partes = $r['candidates'][0]['content']['parts'] ?? [];
            $llamadas = array_values(array_filter($partes, fn ($p) => isset($p['functionCall'])));
            if (empty($llamadas)) {
                return implode('', array_map(fn ($p) => $p['text'] ?? '', $partes));
            }
            $contenidos[] = ['role' => 'model', 'parts' => $partes];
            $respuestas = [];
            foreach ($llamadas as $llamada) {
                $resultado = PlacaService::consultar($llamada['functionCall']['args']['placa'] ?? '');
                if ($resultado['estado'] ?? null) {
                    $resultado['estado_texto'] = self::ETIQUETAS[$resultado['estado']];
                }
                $consultas[] = $resultado;
                $respuestas[] = ['functionResponse' => ['name' => 'consultar_placa', 'response' => $resultado]];
            }
            $contenidos[] = ['role' => 'user', 'parts' => $respuestas];
        }

        return '';
    }

    private function extraerPlaca(string $mensaje): ?string
    {
        if (preg_match('/leyó la placa ([A-Z0-9]+)/u', $mensaje, $m)) {
            return $m[1];
        }
        // Candidatos: cada palabra y cada par de palabras cortas ("2580 BOS"). Gana el más largo válido.
        $palabras = array_values(array_filter(preg_split('/[\s,;:¿?¡!.]+/u', $mensaje)));
        $mejor = null;
        foreach ($palabras as $i => $p) {
            $candidatos = [PlacaService::normalizar($p)];
            if (isset($palabras[$i + 1]) && mb_strlen($p) <= 4 && mb_strlen($palabras[$i + 1]) <= 4) {
                $candidatos[] = PlacaService::normalizar($p.$palabras[$i + 1]);
            }
            foreach ($candidatos as $c) {
                if (PlacaService::formatoValido($c) && strlen($c) > strlen((string) $mejor)) {
                    $mejor = $c;
                }
            }
        }

        return $mejor;
    }

    private function plantilla(array $r): string
    {
        if (! $r['formato_valido']) {
            $s = $r['sugerencias'] ? ' ¿Quisiste decir '.implode(', ', $r['sugerencias']).'?' : '';

            return "La placa «{$r['placa']}» no tiene un formato válido. Escríbela de nuevo (ej. 1234ABC).".$s;
        }
        $p = $r['placa'];
        $texto = match ($r['estado']) {
            PlacaService::REGISTRADA_CON_PASO => sprintf(
                'Placa %s: Registrada y con paso. Último paso el %s en la caseta %s, categoría %s, monto Bs %.2f (%d pasos en total).',
                $p, $r['ultimo_paso']['fecha'], $r['ultimo_paso']['caseta'], $r['ultimo_paso']['categoria'] ?? '-', $r['ultimo_paso']['monto'], $r['total_pasos']
            ),
            PlacaService::REGISTRADA_SIN_PASO => sprintf('Placa %s: Registrada sin paso. Está registrada (%s) pero nunca pasó por el peaje.', $p, $r['vehiculo']['categoria']),
            PlacaService::IDENTIFICADA_NO_REGISTRADA => sprintf(
                'Placa %s: Identificada no registrada. Fue detectada por la cámara el %s en %s, pero no está registrada (posible evasión).',
                $p, $r['ultimo_paso']['fecha'], $r['ultimo_paso']['caseta']
            ),
            default => "Placa {$p}: No existe. No hay ningún registro de esa placa en el sistema.",
        };
        if (! empty($r['sugerencias'])) {
            $texto .= ' ¿Quisiste decir '.implode(' o ', $r['sugerencias']).'?';
        }

        return $texto;
    }
}
