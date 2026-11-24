<?php

namespace App\Services;

use App\Events\AlertaCreada;
use App\Events\PasoRegistrado;
use App\Models\Alerta;
use App\Models\Caseta;
use App\Models\Evidencia;
use App\Models\Paso;
use App\Models\Tarifa;
use App\Models\Usuario;
use App\Models\Vehiculo;
use Illuminate\Support\Facades\DB;

/** Lógica de negocio: tarifa, cobro, estados y alertas de cada paso. */
class PeajeService
{
    /** Registra un paso a partir del JSON devuelto por el endpoint de inferencia. */
    public function registrar(array $inf, Caseta $caseta, ?Usuario $usuario): array
    {
        $placa = PlacaService::normalizar($inf['placa'] ?? '');
        $confianza = (float) ($inf['confianza'] ?? 0);
        $base = ['registrado' => false, 'motivo' => null, 'paso' => null, 'alerta' => null, 'duplicado' => false];

        if ($placa === '' || ! PlacaService::formatoValido($placa)) {
            return ['motivo' => 'sin_placa_legible'] + $base;
        }
        if ($confianza < (float) config('peaje.confianza_minima')) {
            return ['motivo' => 'confianza_baja'] + $base;
        }

        // Varios frames del mismo vehículo: un solo paso por placa y caseta dentro de la ventana.
        $reciente = Paso::with('caseta')->where('placa_normalizada', $placa)->where('caseta_id', $caseta->id)
            ->where('created_at', '>=', now()->subSeconds((int) config('peaje.ventana_duplicado_s')))
            ->latest()->first();
        if ($reciente) {
            return ['motivo' => 'duplicado', 'paso' => $reciente, 'alerta' => $reciente->alertas()->first(), 'duplicado' => true] + $base;
        }

        [$paso, $alerta] = DB::transaction(function () use ($inf, $placa, $confianza, $caseta, $usuario) {
            $vehiculo = Vehiculo::where('placa', $placa)->lockForUpdate()->first();
            $categoria = $inf['categoria'] ?? $vehiculo?->categoria;
            $ejes = $inf['ejes'] ?? $vehiculo?->ejes;
            $monto = Tarifa::calcular($categoria, $ejes);

            $tipoAlerta = null;
            $detalle = null;
            if (! $vehiculo) {
                $estado = 'no_registrada';
                $tipoAlerta = 'no_registrada';
                $detalle = "Placa {$placa} no registrada (posible evasión)";
            } elseif (! $vehiculo->activo) {
                $estado = 'vehiculo_inactivo';
                $tipoAlerta = 'vehiculo_inactivo';
                $detalle = "Vehículo {$placa} inactivo";
            } elseif ((float) $vehiculo->saldo < $monto) {
                $estado = 'sin_saldo';
                $tipoAlerta = 'sin_saldo';
                $detalle = "Saldo Bs {$vehiculo->saldo} insuficiente para Bs ".number_format($monto, 2);
            } else {
                $estado = 'cobrado';
                $vehiculo->decrement('saldo', $monto);
            }

            $imagenUrl = null;
            if (! empty($inf['evidencia_jpg_base64'])) {
                $evidencia = Evidencia::create(['datos_base64' => $inf['evidencia_jpg_base64']]);
                $imagenUrl = route('evidencias.ver', $evidencia, false);
            }

            $paso = Paso::create([
                'placa_leida' => $inf['placa_leida'] ?? $placa,
                'placa_normalizada' => $placa,
                'confianza_ocr' => $confianza,
                'categoria_detectada' => $categoria,
                'ejes_detectados' => $ejes,
                'pasajeros' => $inf['pasajeros'] ?? null,
                'monto' => $estado === 'cobrado' ? $monto : 0,
                'caseta_id' => $caseta->id,
                'usuario_id' => $usuario?->id,
                'imagen_url' => $imagenUrl,
                'estado' => $estado,
                'latencia_ms' => $inf['latencia_ms'] ?? null,
            ]);
            $alerta = $tipoAlerta ? Alerta::create(['paso_id' => $paso->id, 'tipo' => $tipoAlerta, 'detalle' => $detalle]) : null;

            return [$paso->load('caseta'), $alerta];
        });

        // Tiempo real: el panel recibe el paso, la alerta y los contadores sin recargar.
        rescue(fn () => broadcast(new PasoRegistrado($paso)));
        if ($alerta) {
            rescue(fn () => broadcast(new AlertaCreada($alerta->load('paso.caseta'))));
        }

        return ['registrado' => true, 'paso' => $paso, 'alerta' => $alerta] + $base;
    }

    /** Contadores del día (global o por caseta). */
    public static function resumenDia(?int $casetaId = null): array
    {
        $hoy = now()->startOfDay();
        $q = Paso::where('created_at', '>=', $hoy);
        if ($casetaId) {
            $q->where('caseta_id', $casetaId);
        }
        $alertas = Alerta::where('created_at', '>=', $hoy);
        if ($casetaId) {
            $alertas->whereHas('paso', fn ($p) => $p->where('caseta_id', $casetaId));
        }

        return [
            'fecha' => $hoy->toDateString(),
            'caseta_id' => $casetaId,
            'pasos' => (clone $q)->count(),
            'recaudacion' => round((float) (clone $q)->where('estado', 'cobrado')->sum('monto'), 2),
            'alertas' => $alertas->count(),
            'alertas_pendientes' => Alerta::where('atendida', false)->count(),
        ];
    }
}
