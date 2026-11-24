@extends('layouts.app')
@section('titulo', 'Vehículos')
@section('contenido')
<div class="fila" style="justify-content:space-between">
    <h1>Vehículos registrados</h1>
    @if(auth()->user()->esAdmin())<a class="btn" href="{{ route('vehiculos.create') }}">+ Nuevo vehículo</a>@endif
</div>
<form class="fila tarjeta" method="get">
    <input name="buscar" value="{{ request('buscar') }}" placeholder="Placa o propietario">
    <button class="btn">Buscar</button>
</form>
<div class="tarjeta tabla-scroll">
    <table>
        <thead><tr><th>Placa</th><th>Propietario</th><th>Categoría</th><th>Ejes</th><th>Saldo</th><th>Activo</th>@if(auth()->user()->esAdmin())<th></th>@endif</tr></thead>
        <tbody>
        @forelse($vehiculos as $v)
            <tr>
                <td class="placa">{{ $v->placa }}</td>
                <td>{{ $v->propietario }}</td>
                <td>{{ $v->categoria }}</td>
                <td class="num">{{ $v->ejes }}</td>
                <td class="num">Bs {{ number_format($v->saldo, 2) }}</td>
                <td>{!! $v->activo ? '<span class="chip cobrado">sí</span>' : '<span class="chip no_registrada">no</span>' !!}</td>
                @if(auth()->user()->esAdmin())
                <td class="fila">
                    <a class="btn sec" href="{{ route('vehiculos.edit', $v) }}">Editar</a>
                    <form method="post" action="{{ route('vehiculos.destroy', $v) }}" onsubmit="return confirm('¿Eliminar {{ $v->placa }}?')">@csrf @method('delete')<button class="btn peligro">Eliminar</button></form>
                </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="7" class="suave">Sin vehículos.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div style="margin-top:12px">{{ $vehiculos->links('partes.paginacion') }}</div>
</div>
@endsection
