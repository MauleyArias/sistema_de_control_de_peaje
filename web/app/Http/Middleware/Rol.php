<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Uso: ->middleware('rol:admin') o 'rol:admin,operador'. También corta a usuarios desactivados. */
class Rol
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $usuario = $request->user();
        if (! $usuario || ! $usuario->activo) {
            abort(403, 'Cuenta desactivada.');
        }
        if ($roles && ! in_array($usuario->rol, $roles, true)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Tu rol no permite esta acción.'], 403);
            }
            abort(403, 'Tu rol no permite esta acción.');
        }

        return $next($request);
    }
}
