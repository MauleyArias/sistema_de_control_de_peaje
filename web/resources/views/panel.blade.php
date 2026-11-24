@extends('layouts.app')
@section('titulo', 'Panel en tiempo real')
@section('contenido')
<div class="fila" style="justify-content:space-between">
    <h1>Panel en tiempo real</h1>
    <span class="rt" id="estado-rt">Tiempo real: conectando…</span>
</div>

<div class="rejilla">
    <div class="tarjeta kpi"><div class="etiqueta">Pasos de hoy</div><div class="valor" id="k-pasos">{{ $resumen['pasos'] }}</div></div>
    <div class="tarjeta kpi"><div class="etiqueta">Recaudación de hoy</div><div class="valor">Bs <span id="k-recaudacion">{{ number_format($resumen['recaudacion'], 2) }}</span></div></div>
    <div class="tarjeta kpi"><div class="etiqueta">Alertas de hoy</div><div class="valor" id="k-alertas" style="color:var(--alerta)">{{ $resumen['alertas'] }}</div></div>
    <div class="tarjeta kpi"><div class="etiqueta">Alertas pendientes</div><div class="valor" id="k-pendientes">{{ $resumen['alertas_pendientes'] }}</div></div>
</div>

<div class="rejilla" style="grid-template-columns: minmax(0,2fr) minmax(0,1fr)">
    <div class="tarjeta">
        <h2>Últimos pasos</h2>
        <div class="tabla-scroll">
        <table>
            <thead><tr><th>Evidencia</th><th>Placa</th><th>Categoría</th><th>Ejes</th><th>Pasajeros</th><th>Monto</th><th>Estado</th><th>Caseta</th><th>Hora</th></tr></thead>
            <tbody id="tabla-pasos">
            @foreach($pasos as $p)
                @include('partes.fila-paso', ['p' => $p])
            @endforeach
            </tbody>
        </table>
        </div>
    </div>
    <div>
        <div class="tarjeta">
            <h2>Alertas pendientes</h2>
            <div id="lista-alertas">
            @forelse($alertas as $a)
                <div class="suave" style="padding:8px 0;border-bottom:1px solid var(--borde)"><span class="chip {{ $a['tipo'] }}">{{ str_replace('_', ' ', $a['tipo']) }}</span> <span class="placa">{{ $a['placa'] }}</span><br>{{ $a['detalle'] }} · {{ $a['hora'] }}</div>
            @empty
                <p class="suave" id="sin-alertas">Sin alertas pendientes.</p>
            @endforelse
            </div>
        </div>
        <div class="tarjeta">
            <h2>Probar caseta desde el navegador</h2>
            <p class="suave">Mismo flujo que la app móvil: foto → inferencia → paso → tiempo real.</p>
            <form id="form-caseta" class="fila">
                <select name="caseta_id">@foreach($casetas as $c)<option value="{{ $c->id }}">{{ $c->nombre }}</option>@endforeach</select>
                <input type="file" name="imagen" accept="image/*" required style="max-width:100%">
                <button class="btn">Enviar</button>
            </form>
            <pre id="res-caseta" class="suave" style="white-space:pre-wrap"></pre>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const csrf = document.querySelector('meta[name=csrf-token]').content;
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function filaPaso(p) {
    const tr = document.createElement('tr');
    tr.className = 'nuevo';
    tr.innerHTML = `<td>${p.imagen_url ? `<a href="${esc(p.imagen_url)}" target="_blank"><img class="mini" src="${esc(p.imagen_url)}" alt="evidencia"></a>` : '—'}</td>
        <td class="placa">${esc(p.placa)}</td><td>${esc(p.categoria)}</td><td class="num">${esc(p.ejes)}</td><td class="num">${esc(p.pasajeros)}</td>
        <td class="num">Bs ${Number(p.monto).toFixed(2)}</td><td><span class="chip ${esc(p.estado)}">${esc(p.estado.replace('_',' '))}</span></td>
        <td>${esc(p.caseta)}</td><td class="suave">${esc(p.hora)}</td>`;
    return tr;
}
if (window.canalPanel) {
    const rt = document.getElementById('estado-rt');
    window.peajeRT.connection.bind('state_change', (s) => rt.innerHTML = 'Tiempo real: <b>' + s.current + '</b>');
    window.canalPanel.bind('paso.registrado', (d) => {
        const tbody = document.getElementById('tabla-pasos');
        tbody.prepend(filaPaso(d.paso));
        while (tbody.children.length > 15) tbody.lastElementChild.remove();
        document.getElementById('k-pasos').textContent = d.resumen.pasos;
        document.getElementById('k-recaudacion').textContent = Number(d.resumen.recaudacion).toFixed(2);
        document.getElementById('k-alertas').textContent = d.resumen.alertas;
        document.getElementById('k-pendientes').textContent = d.resumen.alertas_pendientes;
    });
    window.canalPanel.bind('alerta.creada', (d) => {
        document.getElementById('sin-alertas')?.remove();
        const div = document.createElement('div');
        div.className = 'suave nuevo';
        div.style.cssText = 'padding:8px 0;border-bottom:1px solid var(--borde)';
        div.innerHTML = `<span class="chip ${esc(d.alerta.tipo)}">${esc(d.alerta.tipo.replace('_',' '))}</span> <span class="placa">${esc(d.alerta.placa)}</span><br>${esc(d.alerta.detalle)} · ${esc(d.alerta.hora)}`;
        document.getElementById('lista-alertas').prepend(div);
    });
} else {
    document.getElementById('estado-rt').textContent = 'Tiempo real: Pusher no configurado';
}
document.getElementById('form-caseta').addEventListener('submit', async (e) => {
    e.preventDefault();
    const salida = document.getElementById('res-caseta');
    salida.textContent = 'Procesando…';
    const r = await fetch('{{ route('caseta.web') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: new FormData(e.target) });
    salida.textContent = JSON.stringify(await r.json(), null, 2);
});
</script>
@endpush
