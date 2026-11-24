@extends('layouts.app')
@section('titulo', 'Acceso denegado')
@section('contenido')
<div class="tarjeta" style="max-width:460px;margin:12vh auto;text-align:center;padding:32px">
    <div style="font-size:40px">⛔</div>
    <h1>Acceso denegado</h1>
    <p>La cuenta <b>{{ $email ?? 'de Google' }}</b> no está autorizada para usar el sistema.</p>
    <p class="suave">Solicita a un administrador que agregue tu correo a la lista blanca.</p>
    <a class="btn sec" href="{{ route('login') }}">Volver</a>
</div>
@endsection
