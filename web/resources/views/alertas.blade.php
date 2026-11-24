@extends('layouts.app')
@section('titulo', 'Alertas')
@section('contenido')
<h1>Alertas</h1>
<p class="suave">Se generan cuando se detecta una placa no registrada, sin saldo o de un vehículo inactivo. Las nuevas aparecen al instante (aviso rojo abajo a la derecha).</p>
<div class="tarjeta tabla-scroll">
    <table>
        <thead><tr><th>Evidencia</th><th>Tipo</th><th>Placa</th><th>Detalle</th><th>Caseta</th><th>Hora</th><th>Estado</th></tr></thead>
        <tbody>
        @forelse($alertas as $alerta)
            @php($a = $alerta->paraPanel())
            <tr>
                <td>@if($a['imagen_url'])<a href="{{ $a['imagen_url'] }}" target="_blank"><img class="mini" src="{{ $a['imagen_url'] }}" alt="" loading="lazy"></a>@else — @endif</td>
                <td><span class="chip {{ $a['tipo'] }}">{{ str_replace('_', ' ', $a['tipo']) }}</span></td>
                <td class="placa">{{ $a['placa'] }}</td>
                <td>{{ $a['detalle'] }}</td>
                <td>{{ $a['caseta'] }}</td>
                <td class="suave">{{ $a['hora'] }}</td>
                <td>
                    @if($a['atendida'])<span class="chip cobrado">atendida</span>
                    @else<form method="post" action="{{ route('alertas.atender', $alerta) }}">@csrf<button class="btn sec">Marcar atendida</button></form>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="suave">Sin alertas.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div style="margin-top:12px">{{ $alertas->links('partes.paginacion') }}</div>
</div>
@endsection
