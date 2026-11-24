<?php

use App\Http\Controllers\Api\PeajeApiController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Única ruta pública: canjea el ID token de Google por el token del sistema.
    Route::post('/auth/google', [AuthController::class, 'api'])->middleware('throttle:20,1');

    Route::middleware(['auth:sanctum', 'rol:admin,operador', 'throttle:240,1'])->group(function () {
        Route::get('/yo', [PeajeApiController::class, 'yo']);
        Route::get('/casetas', [PeajeApiController::class, 'casetas']);
        Route::post('/pasos/frame', [PeajeApiController::class, 'frame']);
        Route::post('/placas/consultar', [PeajeApiController::class, 'consultar']);
        Route::post('/placas/consultar-imagen', [PeajeApiController::class, 'consultarImagen']);
        Route::get('/placas/{placa}/historial', [PeajeApiController::class, 'historial']);
        Route::get('/casetas/{caseta}/resumen', [PeajeApiController::class, 'resumen']);
        Route::post('/agente/chat', [PeajeApiController::class, 'chat']);
    });
});
