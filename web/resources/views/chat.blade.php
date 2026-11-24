@extends('layouts.app')
@section('titulo', 'Agente')
@section('contenido')
<h1>Agente conversacional</h1>
<p class="suave">Escribe una placa (ej. <span class="placa">2580-BOS</span>) o adjunta una foto. El agente consulta la base de datos y responde uno de los 4 estados; no inventa datos.</p>
<div class="tarjeta" style="max-width:820px">
    <div id="mensajes" style="min-height:300px;max-height:60vh;overflow-y:auto;display:flex;flex-direction:column;gap:10px;padding-bottom:10px"></div>
    <form id="form-chat" class="fila" style="border-top:1px solid var(--borde);padding-top:12px">
        <input name="mensaje" id="mensaje" placeholder="Escribe una placa o una pregunta…" style="flex:1;min-width:180px" autocomplete="off">
        <label class="btn sec" style="cursor:pointer">📷 Foto<input type="file" name="imagen" id="imagen" accept="image/*" hidden></label>
        <button class="btn">Enviar</button>
    </form>
    <div id="adjunto" class="suave"></div>
</div>
@endsection

@push('scripts')
<script>
const csrf = document.querySelector('meta[name=csrf-token]').content;
const historial = [];
const caja = document.getElementById('mensajes');
const etiquetas = { registrada_con_paso: 'Registrada y con paso', registrada_sin_paso: 'Registrada sin paso', identificada_no_registrada: 'Identificada no registrada', no_existe: 'No existe' };
const colores = { registrada_con_paso: 'cobrado', registrada_sin_paso: 'sin_saldo', identificada_no_registrada: 'no_registrada', no_existe: '' };

function burbuja(texto, mio, extra = '') {
    const d = document.createElement('div');
    d.style.cssText = `align-self:${mio ? 'flex-end' : 'flex-start'};max-width:80%;padding:10px 14px;border-radius:12px;background:${mio ? 'var(--primario)' : '#eef1f7'};color:${mio ? '#fff' : 'inherit'}`;
    d.textContent = texto;
    if (extra) d.insertAdjacentHTML('beforeend', extra);
    caja.appendChild(d);
    caja.scrollTop = caja.scrollHeight;
    return d;
}
burbuja('Hola, soy el agente del peaje. Dime una placa o envíame una foto de ella.', false);
document.getElementById('imagen').addEventListener('change', (e) => {
    document.getElementById('adjunto').textContent = e.target.files[0] ? 'Adjunto: ' + e.target.files[0].name : '';
});
document.getElementById('form-chat').addEventListener('submit', async (e) => {
    e.preventDefault();
    const datos = new FormData(e.target);
    const texto = datos.get('mensaje');
    const foto = datos.get('imagen');
    if (!texto && !(foto && foto.size)) return;
    if (!(foto && foto.size)) datos.delete('imagen');
    burbuja((texto || '') + (foto && foto.size ? ' 📷 ' + foto.name : ''), true);
    historial.slice(-6).forEach((h, i) => { datos.append(`historial[${i}][rol]`, h.rol); datos.append(`historial[${i}][texto]`, h.texto); });
    e.target.reset();
    document.getElementById('adjunto').textContent = '';
    const espera = burbuja('…', false);
    try {
        const r = await fetch('{{ route('chat.enviar') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: datos });
        const j = await r.json();
        espera.remove();
        let extra = '';
        if (j.estado) extra += `<div style="margin-top:6px"><span class="chip ${colores[j.estado]}">${etiquetas[j.estado]}</span> <span class="suave">· ${j.motor}</span></div>`;
        if (j.lectura_imagen && j.lectura_imagen.placa) extra += `<div class="suave">OCR: ${j.lectura_imagen.placa} (${Math.round((j.lectura_imagen.confianza || 0) * 100)}%)</div>`;
        burbuja(j.respuesta || j.message || 'Error', false, extra);
        historial.push({ rol: 'user', texto: texto || '[imagen]' }, { rol: 'model', texto: j.respuesta || '' });
    } catch (err) {
        espera.remove();
        burbuja('No se pudo contactar al agente: ' + err.message, false);
    }
});
</script>
@endpush
