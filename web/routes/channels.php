<?php

use App\Models\Usuario;
use Illuminate\Support\Facades\Broadcast;

// Canal privado del panel: solo usuarios activos de la lista blanca.
Broadcast::channel('panel', fn (Usuario $usuario) => $usuario->activo);
