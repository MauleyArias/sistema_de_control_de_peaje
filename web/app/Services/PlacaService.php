<?php

namespace App\Services;

use App\Models\Paso;
use App\Models\Vehiculo;

/** Normalización, validación, sugerencias por errores de OCR y estado de una placa. */
class PlacaService
{
    /** Confusiones típicas del OCR. */
    private const CONFUSIONES = ['O' => '0', '0' => 'O', 'I' => '1', '1' => 'I', 'B' => '8', '8' => 'B', 'S' => '5', '5' => 'S', 'Z' => '2', '2' => 'Z', 'G' => '6', '6' => 'G'];

    public const REGISTRADA_CON_PASO = 'registrada_con_paso';
    public const REGISTRADA_SIN_PASO = 'registrada_sin_paso';
    public const IDENTIFICADA_NO_REGISTRADA = 'identificada_no_registrada';
    public const NO_EXISTE = 'no_existe';

    public static function normalizar(?string $placa): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $placa));
    }

    /** Placas de 4 a 8 caracteres alfanuméricos con al menos un dígito (formato boliviano: 1234ABC). */
    public static function formatoValido(string $normalizada): bool
    {
        return (bool) preg_match('/^(?=.*\d)[A-Z0-9]{4,8}$/', $normalizada);
    }

    public static function formatoBoliviano(string $normalizada): bool
    {
        return (bool) preg_match('/^\d{3,4}[A-Z]{3}$/', $normalizada);
    }

    /** Todas las variantes que difieren por confusiones de OCR (máx. 2 sustituciones). */
    public static function variantes(string $placa): array
    {
        $resultado = [$placa => true];
        $frontera = [$placa];
        for ($nivel = 0; $nivel < 2; $nivel++) {
            $nueva = [];
            foreach ($frontera as $p) {
                for ($i = 0; $i < strlen($p); $i++) {
                    if (isset(self::CONFUSIONES[$p[$i]])) {
                        $v = substr_replace($p, self::CONFUSIONES[$p[$i]], $i, 1);
                        if (! isset($resultado[$v])) {
                            $resultado[$v] = true;
                            $nueva[] = $v;
                        }
                    }
                }
            }
            $frontera = $nueva;
        }
        unset($resultado[$placa]);

        return array_keys($resultado);
    }

    /** "¿Quisiste decir…?": placas conocidas parecidas (confusiones de OCR o distancia de edición 1). */
    public static function sugerencias(string $placa, int $limite = 5): array
    {
        $conocidas = Vehiculo::pluck('placa')
            ->merge(Paso::whereNotNull('placa_normalizada')->distinct()->pluck('placa_normalizada'))
            ->unique()->values();
        $variantes = array_flip(self::variantes($placa));
        $sugeridas = [];
        foreach ($conocidas as $c) {
            if ($c === $placa) {
                continue;
            }
            if (isset($variantes[$c]) || (abs(strlen($c) - strlen($placa)) <= 1 && levenshtein($c, $placa) === 1)) {
                $sugeridas[] = $c;
            }
        }

        return array_slice($sugeridas, 0, $limite);
    }

    /** Estado de la placa según la BD. Nunca inventa: todo sale de las tablas vehiculos y pasos. */
    public static function consultar(?string $texto): array
    {
        $placa = self::normalizar($texto);
        if ($placa === '' || ! self::formatoValido($placa)) {
            return [
                'placa' => $placa,
                'formato_valido' => false,
                'estado' => null,
                'mensaje' => 'Formato de placa no válido (se esperan 4 a 8 letras/números con al menos un dígito, p. ej. 1234ABC).',
                'sugerencias' => $placa !== '' ? self::sugerencias($placa) : [],
            ];
        }

        $vehiculo = Vehiculo::where('placa', $placa)->first();
        $pasos = Paso::with('caseta')->where('placa_normalizada', $placa)->latest()->get();
        $ultimo = $pasos->first();

        $estado = match (true) {
            $vehiculo && $pasos->isNotEmpty() => self::REGISTRADA_CON_PASO,
            (bool) $vehiculo => self::REGISTRADA_SIN_PASO,
            $pasos->isNotEmpty() => self::IDENTIFICADA_NO_REGISTRADA,
            default => self::NO_EXISTE,
        };

        return [
            'placa' => $placa,
            'formato_valido' => true,
            'formato_boliviano' => self::formatoBoliviano($placa),
            'estado' => $estado,
            'vehiculo' => $vehiculo ? [
                'propietario' => $vehiculo->propietario,
                'categoria' => $vehiculo->categoria,
                'ejes' => $vehiculo->ejes,
                'saldo' => (float) $vehiculo->saldo,
                'activo' => $vehiculo->activo,
            ] : null,
            'total_pasos' => $pasos->count(),
            'ultimo_paso' => $ultimo ? [
                'fecha' => $ultimo->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'caseta' => $ultimo->caseta?->nombre,
                'categoria' => $ultimo->categoria_detectada,
                'ejes' => $ultimo->ejes_detectados,
                'monto' => (float) $ultimo->monto,
                'estado' => $ultimo->estado,
            ] : null,
            'sugerencias' => in_array($estado, [self::NO_EXISTE, self::IDENTIFICADA_NO_REGISTRADA]) ? self::sugerencias($placa) : [],
        ];
    }
}
