<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehiculo extends Model
{
    public const CATEGORIAS = ['moto', 'auto', 'minibus', 'camion', 'bus'];

    protected $fillable = ['placa', 'propietario', 'categoria', 'ejes', 'saldo', 'activo'];

    protected function casts(): array
    {
        return ['saldo' => 'decimal:2', 'activo' => 'boolean', 'ejes' => 'integer'];
    }

    public function pasos()
    {
        return $this->hasMany(Paso::class, 'placa_normalizada', 'placa');
    }
}
