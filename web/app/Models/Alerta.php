<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Alerta extends Model
{
    protected $fillable = ['paso_id', 'tipo', 'detalle', 'atendida'];

    protected function casts(): array
    {
        return ['atendida' => 'boolean'];
    }

    public function paso()
    {
        return $this->belongsTo(Paso::class);
    }

    public function paraPanel(): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'detalle' => $this->detalle,
            'atendida' => $this->atendida,
            'placa' => $this->paso?->placa_normalizada,
            'caseta' => $this->paso?->caseta?->nombre,
            'imagen_url' => $this->paso?->imagen_url,
            'hora' => $this->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        ];
    }
}
