<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caseta;
use App\Models\Paso;
use App\Services\AgenteService;
use App\Services\InferenciaService;
use App\Services\PeajeService;
use App\Services\PlacaService;
use Illuminate\Http\Request;

class PeajeApiController extends Controller
{
    public function __construct(private InferenciaService $inferencia) {}

    public function yo(Request $request)
    {
        return $request->user()->only(['id', 'email_google', 'nombre', 'rol', 'avatar']);
    }

    public function casetas()
    {
        return Caseta::orderBy('id')->get(['id', 'nombre', 'ubicacion']);
    }

    /** POST /api/v1/pasos/frame — frame de la caseta -> inferencia -> paso -> tiempo real. */
    public function frame(Request $request)
    {
        $request->validate([
            'imagen' => 'required|file|mimes:jpg,jpeg,png|max:6144',
            'caseta_id' => 'required|integer|exists:casetas,id',
        ]);
        $inicio = microtime(true);
        try {
            $inf = $this->inferencia->inferir($request->file('imagen')->get());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $r = app(PeajeService::class)->registrar($inf, Caseta::findOrFail($request->caseta_id), $request->user());
        $paso = $r['paso'];

        return response()->json([
            'registrado' => $r['registrado'],
            'duplicado' => $r['duplicado'],
            'motivo' => $r['motivo'],
            'placa' => $paso?->placa_normalizada ?? ($inf['placa'] ?? null),
            'confianza' => $inf['confianza'] ?? null,
            'categoria' => $paso?->categoria_detectada ?? ($inf['categoria'] ?? null),
            'ejes' => $paso?->ejes_detectados ?? ($inf['ejes'] ?? null),
            'pasajeros' => $paso?->pasajeros ?? ($inf['pasajeros'] ?? null),
            'tarifa' => $paso ? (float) $paso->monto : null,
            'estado' => $paso?->estado,
            'alerta' => $r['alerta']?->tipo,
            'paso_id' => $paso?->id,
            'latencia_inferencia_ms' => $inf['latencia_ms'] ?? null,
            'latencia_total_ms' => (int) ((microtime(true) - $inicio) * 1000),
            'tiempos' => $inf['tiempos'] ?? null,
        ]);
    }

    /** POST /api/v1/placas/consultar  {placa} */
    public function consultar(Request $request)
    {
        $request->validate(['placa' => 'required|string|max:20']);

        return PlacaService::consultar($request->placa);
    }

    /** POST /api/v1/placas/consultar-imagen  (imagen) — M1 + OCR y luego estado. */
    public function consultarImagen(Request $request)
    {
        $request->validate(['imagen' => 'required|file|mimes:jpg,jpeg,png|max:6144']);
        try {
            $inf = $this->inferencia->inferir($request->file('imagen')->get(), soloPlaca: true);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
        $placa = PlacaService::normalizar($inf['placa'] ?? '');
        if ($placa === '' || ! PlacaService::formatoValido($placa)) {
            return response()->json([
                'placa_detectada' => (bool) ($inf['placa_detectada'] ?? false),
                'placa' => $placa ?: null,
                'estado' => null,
                'mensaje' => 'No se encontró una placa legible en la imagen. Envía otra foto.',
            ], 422);
        }

        return ['lectura' => ['placa' => $placa, 'confianza' => $inf['confianza'] ?? null, 'latencia_ms' => $inf['latencia_ms'] ?? null]]
            + PlacaService::consultar($placa);
    }

    /** GET /api/v1/placas/{placa}/historial */
    public function historial(string $placa)
    {
        $placa = PlacaService::normalizar($placa);

        return [
            'placa' => $placa,
            'pasos' => Paso::with('caseta')->where('placa_normalizada', $placa)->latest()->limit(100)->get()
                ->map(fn (Paso $p) => $p->paraPanel()),
        ];
    }

    /** GET /api/v1/casetas/{id}/resumen */
    public function resumen(Caseta $caseta)
    {
        return ['caseta' => $caseta->only(['id', 'nombre', 'ubicacion'])] + PeajeService::resumenDia($caseta->id);
    }

    /** POST /api/v1/agente/chat  {mensaje?, imagen?, historial?} */
    public function chat(Request $request, AgenteService $agente)
    {
        $request->validate([
            'mensaje' => 'nullable|string|max:500',
            'imagen' => 'nullable|file|mimes:jpg,jpeg,png|max:6144',
            'historial' => 'nullable|array|max:10',
        ]);
        if (! $request->filled('mensaje') && ! $request->hasFile('imagen')) {
            return response()->json(['message' => 'Envía un mensaje o una imagen.'], 422);
        }
        $lectura = null;
        if ($request->hasFile('imagen')) {
            try {
                $lectura = $this->inferencia->inferir($request->file('imagen')->get(), soloPlaca: true);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 503);
            }
        }

        return $agente->responder((string) $request->input('mensaje', ''), $lectura, $request->input('historial', []));
    }
}
