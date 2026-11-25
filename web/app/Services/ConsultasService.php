<?php

namespace App\Services;

use App\Models\Alerta;
use App\Models\Caseta;
use App\Models\Paso;
use App\Models\Tarifa;
use App\Models\Vehiculo;

/** Consultas generales del agente (solo lectura). Todo sale de la base de datos. */
class ConsultasService
{
    public static function resumen(): array
    {
        return [
            'fecha_hora' => now()->format('Y-m-d H:i'),
            'vehiculos' => [
                'registrados' => Vehiculo::count(),
                'activos' => Vehiculo::where('activo', true)->count(),
                'sin_saldo' => Vehiculo::where('saldo', '<=', 0)->count(),
                'por_categoria' => Vehiculo::selectRaw('categoria, count(*) as n')->groupBy('categoria')->pluck('n', 'categoria')->map(fn ($n) => (int) $n)->all(),
            ],
            'hoy' => PeajeService::resumenDia(),
            'historico' => [
                'pasos' => Paso::count(),
                'recaudacion' => round((float) Paso::where('estado', 'cobrado')->sum('monto'), 2),
                'alertas' => Alerta::count(),
            ],
            'casetas' => Caseta::orderBy('id')->get()->map(fn ($c) => [
                'nombre' => $c->nombre, 'ubicacion' => $c->ubicacion,
                'pasos_hoy' => PeajeService::resumenDia($c->id)['pasos'],
                'recaudacion_hoy' => PeajeService::resumenDia($c->id)['recaudacion'],
            ])->all(),
        ];
    }

    public static function ultimosPasos(int $limite = 5, ?string $estado = null): array
    {
        return Paso::with('caseta')->when($estado, fn ($q) => $q->where('estado', $estado))->latest()->limit(min(max($limite, 1), 20))->get()
            ->map(fn ($p) => collect($p->paraPanel())->only(['placa', 'categoria', 'ejes', 'pasajeros', 'monto', 'estado', 'caseta', 'hora'])->all())->all();
    }

    public static function alertas(bool $soloPendientes = true, int $limite = 5): array
    {
        $q = Alerta::with('paso.caseta')->when($soloPendientes, fn ($q) => $q->where('atendida', false));

        return [
            'total' => (clone $q)->count(),
            'lista' => $q->latest()->limit(min(max($limite, 1), 20))->get()->map(fn ($a) => [
                'tipo' => $a->tipo, 'detalle' => $a->detalle, 'placa' => $a->paso?->placa_normalizada, 'caseta' => $a->paso?->caseta?->nombre, 'atendida' => $a->atendida,
            ])->all(),
        ];
    }

    public static function vehiculos(?string $categoria = null, bool $sinSaldo = false, int $limite = 20): array
    {
        $q = Vehiculo::query()
            ->when(in_array($categoria, Vehiculo::CATEGORIAS, true), fn ($q) => $q->where('categoria', $categoria))
            ->when($sinSaldo, fn ($q) => $q->where('saldo', '<=', 0));

        return [
            'total' => (clone $q)->count(),
            'lista' => $q->orderBy('placa')->limit(min(max($limite, 1), 60))->get()->map(fn ($v) => [
                'placa' => $v->placa, 'categoria' => $v->categoria, 'ejes' => $v->ejes, 'saldo' => (float) $v->saldo, 'activo' => $v->activo,
            ])->all(),
        ];
    }

    public static function tarifas(): array
    {
        return Tarifa::orderBy('categoria')->orderBy('ejes')->get()->map(fn ($t) => ['categoria' => $t->categoria, 'ejes' => $t->ejes, 'monto' => (float) $t->monto])->all();
    }

    /** Ejecuta la herramienta que pidió el LLM. */
    public static function ejecutar(string $nombre, array $a): array
    {
        return match ($nombre) {
            'resumen_sistema' => self::resumen(),
            'ultimos_pasos' => ['pasos' => self::ultimosPasos((int) ($a['limite'] ?? 5), $a['estado'] ?? null)],
            'listar_alertas' => self::alertas((bool) ($a['solo_pendientes'] ?? true), (int) ($a['limite'] ?? 5)),
            'listar_vehiculos' => self::vehiculos($a['categoria'] ?? null, (bool) ($a['sin_saldo'] ?? false), (int) ($a['limite'] ?? 20)),
            'listar_tarifas' => ['tarifas' => self::tarifas()],
            default => ['error' => 'Herramienta desconocida'],
        };
    }

    /** Respuesta sin LLM: palabras clave sobre los mismos datos. Null si no es una consulta del sistema. */
    public static function plantilla(string $mensaje): ?string
    {
        $m = mb_strtolower($mensaje);
        $hay = fn (string $re) => preg_match('/'.$re.'/u', $m) === 1;
        if ($hay('tarifa|precio|cu[aá]nto (cuesta|paga|cobra)')) {
            return 'Tarifas: '.implode('; ', array_map(fn ($t) => "{$t['categoria']} de {$t['ejes']} ejes Bs ".number_format($t['monto'], 2), self::tarifas())).'.';
        }
        if ($hay('alerta')) {
            $a = self::alertas();

            return "Hay {$a['total']} alertas pendientes.".($a['lista'] ? ' Últimas: '.implode('; ', array_map(fn ($x) => str_replace('_', ' ', $x['tipo']).' '.($x['placa'] ?? ''), $a['lista'])).'.' : '');
        }
        if ($hay('sin saldo')) {
            $v = self::vehiculos(null, true);

            return "Hay {$v['total']} vehículos sin saldo".($v['lista'] ? ': '.implode(', ', array_column($v['lista'], 'placa')) : '').'.';
        }
        if ($hay('[uú]ltim[oa]s? (paso|veh|placa)|reci[eé]n')) {
            $p = self::ultimosPasos();

            return $p ? 'Últimos pasos: '.implode('; ', array_map(fn ($x) => "{$x['placa']} ({$x['categoria']}, ".str_replace('_', ' ', $x['estado']).", {$x['hora']})", $p)).'.' : 'Todavía no hay pasos registrados.';
        }
        $r = self::resumen();
        if ($hay('recaud|ganancia|dinero|cobrad')) {
            return 'Recaudación de hoy: Bs '.number_format($r['hoy']['recaudacion'], 2).'. Histórica: Bs '.number_format($r['historico']['recaudacion'], 2).'.';
        }
        if ($hay('caseta')) {
            return 'Casetas: '.implode('; ', array_map(fn ($c) => "{$c['nombre']} ({$c['pasos_hoy']} pasos hoy, Bs ".number_format($c['recaudacion_hoy'], 2).')', $r['casetas'])).'.';
        }
        if ($hay('cu[aá]nt[oa]s? .*pas(o|aron)|pasos')) {
            return "Hoy hay {$r['hoy']['pasos']} pasos; en total {$r['historico']['pasos']}.";
        }
        if ($hay('cu[aá]nt[oa]s?|registrad|veh[ií]culos|resumen')) {
            $cat = implode(', ', array_map(fn ($c, $n) => "$c $n", array_keys($r['vehiculos']['por_categoria']), $r['vehiculos']['por_categoria']));

            return "Hay {$r['vehiculos']['registrados']} placas registradas ({$cat}); {$r['vehiculos']['activos']} activas y {$r['vehiculos']['sin_saldo']} sin saldo. Hoy: {$r['hoy']['pasos']} pasos y Bs ".number_format($r['hoy']['recaudacion'], 2).' recaudados.';
        }

        return null;
    }
}
