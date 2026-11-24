<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Paso extends Model
{
    protected $fillable = [
        'placa_leida', 'placa_normalizada', 'confianza_ocr', 'categoria_detectada', 'ejes_detectados',
        'pasajeros', 'monto', 'caseta_id', 'usuario_id', 'imagen_url', 'estado', 'latencia_ms',
    ];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'confianza_ocr' => 'float'];
    }

    public function caseta()
    {
        return $this->belongsTo(Caseta::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }

    public function alertas()
    {
        return $this->hasMany(Alerta::class);
    }

    public function paraPanel(): array
    {
        return [
            'id' => $this->id,
            'placa' => $this->placa_normalizada,
            'placa_leida' => $this->placa_leida,
            'confianza_ocr' => $this->confianza_ocr,
            'categoria' => $this->categoria_detectada,
            'ejes' => $this->ejes_detectados,
            'pasajeros' => $this->pasajeros,
            'monto' => (float) $this->monto,
            'estado' => $this->estado,
            'caseta' => $this->caseta?->nombre,
            'caseta_id' => $this->caseta_id,
            'imagen_url' => $this->imagen_url,
            'latencia_ms' => $this->latencia_ms,
            'hora' => $this->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        ];
    }
}
