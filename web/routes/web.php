<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\VehiculoController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('panel'));

// Acceso exclusivo con Google.
Route::get('/login', [AuthController::class, 'login'])->name('login');
Route::get('/auth/google', [AuthController::class, 'redirigir'])->name('google.redirect');
Route::get('/auth/google/callback', [AuthController::class, 'callback'])->name('google.callback');
Route::get('/acceso-denegado', [AuthController::class, 'denegado'])->name('acceso-denegado');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Documentación de la API (Swagger UI sobre docs/openapi.yaml).
Route::view('/api/docs', 'docs')->name('api.docs');
Route::get('/api/openapi.yaml', fn () => response()->file(base_path('openapi.yaml'), ['Content-Type' => 'application/yaml']))->name('api.openapi');

Route::middleware(['auth', 'rol:admin,operador'])->group(function () {
    Route::get('/panel', [PanelController::class, 'panel'])->name('panel');
    Route::get('/pasos', [PanelController::class, 'pasos'])->name('pasos');
    Route::get('/alertas', [PanelController::class, 'alertas'])->name('alertas');
    Route::post('/alertas/{alerta}/atender', [PanelController::class, 'atender'])->name('alertas.atender');
    Route::get('/chat', [PanelController::class, 'chat'])->name('chat');
    Route::post('/chat', [PanelController::class, 'chatEnviar'])->name('chat.enviar');
    Route::get('/estado', [PanelController::class, 'estado'])->name('estado');
    Route::post('/caseta-web', [PanelController::class, 'casetaWeb'])->name('caseta.web');
    Route::get('/evidencias/{evidencia}', [PanelController::class, 'evidencia'])->name('evidencias.ver');
    Route::get('/vehiculos', [VehiculoController::class, 'index'])->name('vehiculos.index');
});

// Administrador: vehículos, tarifas, casetas y lista blanca de usuarios.
Route::middleware(['auth', 'rol:admin'])->group(function () {
    Route::resource('vehiculos', VehiculoController::class)->except(['index', 'show']);
    Route::get('/admin', [AdminController::class, 'index'])->name('admin');
    Route::post('/admin/tarifas', [AdminController::class, 'tarifaGuardar'])->name('admin.tarifas');
    Route::delete('/admin/tarifas/{tarifa}', [AdminController::class, 'tarifaBorrar'])->name('admin.tarifas.borrar');
    Route::post('/admin/casetas', [AdminController::class, 'casetaGuardar'])->name('admin.casetas');
    Route::delete('/admin/casetas/{caseta}', [AdminController::class, 'casetaBorrar'])->name('admin.casetas.borrar');
    Route::post('/admin/usuarios', [AdminController::class, 'usuarioGuardar'])->name('admin.usuarios');
    Route::patch('/admin/usuarios/{usuario}', [AdminController::class, 'usuarioCambiar'])->name('admin.usuarios.cambiar');
});
