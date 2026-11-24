@extends('layouts.app')
@section('titulo', 'Pasos')
@section('contenido')
<h1>Historial de pasos</h1>
<form class="fila tarjeta" method="get">
    <input name="placa" value="{{ request('placa') }}" placeholder="Placa">
    <select name="estado">
        <option value="">Todos los estados</option>
        @foreach(['cobrado', 'sin_saldo', 'no_registrada', 'vehiculo_inactivo'] as $e)
            <option value="{{ $e }}" @selected(request('estado') === $e)>{{ str_replace('_', ' ', $e) }}</option>
        @endforeach
    </select>
    <button class="btn">Filtrar</button>
</form>
<div class="tarjeta tabla-scroll">
    <table>
        <thead><tr><th>Evidencia</th><th>Placa</th><th>Categoría</th><th>Ejes</th><th>Pasajeros</th><th>Monto</th><th>Estado</th><th>Caseta</th><th>Hora</th></tr></thead>
        <tbody>
        @forelse($pasos as $paso)
            @include('partes.fila-paso', ['p' => $paso->paraPanel()])
        @empty
            <tr><td colspan="9" class="suave">Sin pasos.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div style="margin-top:12px">{{ $pasos->links('partes.paginacion') }}</div>
</div>
@endsection
