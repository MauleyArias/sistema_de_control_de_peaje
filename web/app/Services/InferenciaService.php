<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Cliente del endpoint de inferencia. La URL vive en INFERENCE_URL (Vast.ai, RunPod o Colab). */
class InferenciaService
{
    /** Cortes pasajeros del túnel o del proxy: se reintenta. Un 401, 422 o 500 no se reintenta. */
    private const REINTENTAR = [502, 503, 504, 520, 521, 522, 523, 530];

    private function cliente()
    {
        $url = rtrim((string) config('peaje.inference_url'), '/');
        if ($url === '') {
            throw new RuntimeException('INFERENCE_URL no está configurada.');
        }

        return Http::baseUrl($url)
            ->withHeaders(['X-API-Key' => (string) config('peaje.inference_key')])
            ->timeout((int) config('peaje.inference_timeout'));
    }

    /** @param  string  $contenido  bytes de la imagen JPG/PNG */
    public function inferir(string $contenido, bool $soloPlaca = false): array
    {
        try {
            $respuesta = $this->cliente()
                ->retry(3, 700, fn ($e) => $e instanceof RequestException
                    && in_array($e->response->status(), self::REINTENTAR, true), throw: false)
                ->attach('imagen', $contenido, 'frame.jpg')
                ->post('/inferir'.($soloPlaca ? '?solo_placa=true' : ''));
        } catch (ConnectionException $e) {
            throw new RuntimeException('El endpoint de inferencia no responde: '.$e->getMessage());
        }
        if (! $respuesta->successful()) {
            throw new RuntimeException('Error de inferencia HTTP '.$respuesta->status().': '.mb_substr($respuesta->body(), 0, 200));
        }

        return $respuesta->json();
    }

    public function salud(): array
    {
        $inicio = microtime(true);
        try {
            $r = $this->cliente()->timeout(15)->get('/salud');

            return ['ok' => $r->successful(), 'http' => $r->status(), 'detalle' => $r->json(), 'ms' => (int) ((microtime(true) - $inicio) * 1000)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'http' => null, 'detalle' => $e->getMessage(), 'ms' => (int) ((microtime(true) - $inicio) * 1000)];
        }
    }
}
