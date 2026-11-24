<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tarifa extends Model
{
    protected $fillable = ['categoria', 'ejes', 'monto'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'ejes' => 'integer'];
    }

    /** Tarifa por categoría; en vehículos pesados el número de ejes define el monto. */
    public static function calcular(?string $categoria, ?int $ejes): float
    {
        if (! $categoria) {
            return 0.0;
        }
        $tarifas = static::where('categoria', $categoria)->orderBy('ejes')->get();
        if ($tarifas->isEmpty()) {
            return 0.0;
        }
        $ejes = $ejes ?: $tarifas->first()->ejes;
        $exacta = $tarifas->firstWhere('ejes', $ejes);
        if ($exacta) {
            return (float) $exacta->monto;
        }
        // Más ejes que la tarifa mayor registrada: se cobra la mayor. Menos: la menor.
        $aplicable = $tarifas->filter(fn ($t) => $t->ejes <= $ejes)->last() ?? $tarifas->first();

        return (float) $aplicable->monto;
    }
}
