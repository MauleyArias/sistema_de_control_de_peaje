<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Caseta extends Model
{
    protected $fillable = ['nombre', 'ubicacion'];

    public function pasos()
    {
        return $this->hasMany(Paso::class);
    }
}
