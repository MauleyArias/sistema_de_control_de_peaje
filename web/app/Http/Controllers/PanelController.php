<?php

namespace App\Http\Controllers;

use App\Models\Alerta;
use App\Models\Caseta;
use App\Models\Evidencia;
use App\Models\Paso;
use App\Services\AgenteService;
use App\Services\InferenciaService;
use App\Services\PeajeService;
use Illuminate\Http\Request;

class PanelController extends Controller
{
    public function panel()
    {
        return view('panel', [
            'resumen' => PeajeService::resumenDia(),
            'pasos' => Paso::with('caseta')->latest()->limit(15)->get()->map->paraPanel(),
            'alertas' => Alerta::with('paso.caseta')->where('atendida', false)->latest()->limit(10)->get()->map->paraPanel(),
            'casetas' => Caseta::orderBy('id')->get(),
        ]);
    }

    public function pasos(Request $request)
    {
        $q = Paso::with('caseta')->latest();
        if ($request->filled('placa')) {
            $q->where('placa_normalizada', 'like', '%'.preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($request->placa)).'%');
        }
        if ($request->filled('estado')) {
            $q->where('estado', $request->estado);
        }

        return view('pasos', ['pasos' => $q->paginate(20)->withQueryString()]);
    }

    public function alertas()
    {
        return view('alertas', ['alertas' => Alerta::with('paso.caseta')->latest()->paginate(25)]);
    }

    public function atender(Alerta $alerta)
    {
        $alerta->update(['atendida' => true]);

        return back()->with('ok', 'Alerta marcada como atendida.');
    }

    public function chat()
    {
        return view('chat');
    }

    public function chatEnviar(Request $request, AgenteService $agente, InferenciaService $inferencia)
    {
        $request->validate([
            'mensaje' => 'nullable|string|max:500',
            'imagen' => 'nullable|file|mimes:jpg,jpeg,png|max:6144',
            'historial' => 'nullable|array|max:10',
        ]);
        $lectura = null;
        if ($request->hasFile('imagen')) {
            try {
                $lectura = $inferencia->inferir($request->file('imagen')->get(), soloPlaca: true);
            } catch (\RuntimeException $e) {
                return response()->json(['respuesta' => 'El servicio de inferencia no responde ahora: no puedo leer la imagen. Escribe la placa en texto.', 'error' => $e->getMessage()], 503);
            }
        } elseif (! $request->filled('mensaje')) {
            return response()->json(['respuesta' => 'Escribe una placa o adjunta una foto.'], 422);
        }

        return $agente->responder((string) $request->input('mensaje', ''), $lectura, $request->input('historial', []));
    }

    public function estado(InferenciaService $inferencia)
    {
        return view('estado', [
            'salud' => $inferencia->salud(),
            'url' => config('peaje.inference_url'),
            'pusher' => (bool) config('broadcasting.connections.pusher.key'),
            'gemini' => (bool) config('peaje.gemini_key'),
        ]);
    }

    /** Caseta desde el navegador (para pruebas): sube una foto y sigue el mismo flujo que la app. */
    public function casetaWeb(Request $request, InferenciaService $inferencia, PeajeService $peaje)
    {
        $request->validate(['imagen' => 'required|file|mimes:jpg,jpeg,png|max:6144', 'caseta_id' => 'required|exists:casetas,id']);
        try {
            $inf = $inferencia->inferir($request->file('imagen')->get());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
        $r = $peaje->registrar($inf, Caseta::find($request->caseta_id), $request->user());

        return ['registrado' => $r['registrado'], 'motivo' => $r['motivo'], 'placa' => $inf['placa'] ?? null,
            'categoria' => $inf['categoria'] ?? null, 'ejes' => $inf['ejes'] ?? null, 'pasajeros' => $inf['pasajeros'] ?? null,
            'estado' => $r['paso']?->estado, 'latencia_ms' => $inf['latencia_ms'] ?? null];
    }

    public function evidencia(Evidencia $evidencia)
    {
        return response(base64_decode($evidencia->datos_base64), 200, [
            'Content-Type' => $evidencia->mime,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
