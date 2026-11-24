<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Cliente del endpoint de inferencia. La URL vive en INFERENCE_URL (RunPod o Colab). */
class InferenciaService
{
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
