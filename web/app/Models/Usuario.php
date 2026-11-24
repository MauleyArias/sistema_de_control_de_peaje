<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'usuarios';

    protected $fillable = ['email_google', 'nombre', 'rol', 'activo', 'google_id', 'avatar'];

    protected $hidden = ['remember_token', 'google_id'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function esAdmin(): bool
    {
        return $this->rol === 'admin';
    }
}
