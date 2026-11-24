@extends('layouts.app')
@section('titulo', 'Estado')
@section('contenido')
<h1>Estado del sistema</h1>
<div class="rejilla">
    <div class="tarjeta">
        <h2>Endpoint de inferencia</h2>
        <p style="font-size:24px;font-weight:700;color:{{ $salud['ok'] ? 'var(--ok)' : 'var(--alerta)' }}">{{ $salud['ok'] ? '● En línea' : '● Sin respuesta' }}</p>
        <p class="suave">INFERENCE_URL: <code>{{ $url ?: '(sin configurar)' }}</code></p>
        <p class="suave">Tiempo de respuesta: {{ $salud['ms'] }} ms · HTTP {{ $salud['http'] ?? '—' }}</p>
        @if(is_array($salud['detalle']))
            <table>
                @foreach($salud['detalle'] as $k => $v)
                    <tr><th>{{ $k }}</th><td>{{ is_bool($v) ? ($v ? 'sí' : 'no') : $v }}</td></tr>
                @endforeach
            </table>
        @else
            <p class="aviso-error">{{ $salud['detalle'] }}</p>
        @endif
        <a class="btn sec" href="{{ route('estado') }}">Volver a comprobar</a>
    </div>
    <div class="tarjeta">
        <h2>Servicios</h2>
        <table>
            <tr><th>Tiempo real (Pusher)</th><td>{{ $pusher ? 'configurado' : 'sin configurar' }} · <span id="rt-estado">—</span></td></tr>
            <tr><th>Agente (Gemini)</th><td>{{ $gemini ? 'configurado ('.config('peaje.gemini_model').')' : 'sin clave: usa respuestas por reglas' }}</td></tr>
            <tr><th>Base de datos</th><td>{{ config('database.default') }}</td></tr>
            <tr><th>Servidor web</th><td>{{ request()->getHost() }} (HTTPS: {{ request()->isSecure() ? 'sí' : 'no' }})</td></tr>
        </table>
    </div>
</div>
@endsection
@push('scripts')
<script>
if (window.peajeRT) { const s = document.getElementById('rt-estado'); s.textContent = window.peajeRT.connection.state; window.peajeRT.connection.bind('state_change', (x) => s.textContent = x.current); }
</script>
@endpush
