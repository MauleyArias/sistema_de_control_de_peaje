<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Peaje') · Control de Peaje</title>
    <style>
        :root { --fondo:#f4f6fa; --tarjeta:#fff; --texto:#1c2333; --suave:#667085; --borde:#e3e7ef; --primario:#1f5eff; --ok:#12805c; --alerta:#c2331f; --aviso:#b26b00; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--fondo); color:var(--texto); font-size:15px; }
        a { color:var(--primario); text-decoration:none; }
        nav { background:#111a2e; color:#fff; display:flex; gap:4px; align-items:center; padding:0 16px; flex-wrap:wrap; }
        nav .marca { font-weight:700; margin-right:16px; padding:14px 0; }
        nav a { color:#c9d3ea; padding:14px 10px; border-bottom:3px solid transparent; }
        nav a.activo, nav a:hover { color:#fff; border-bottom-color:var(--primario); }
        nav .usuario { margin-left:auto; display:flex; gap:10px; align-items:center; font-size:13px; color:#c9d3ea; }
        nav .usuario img { width:28px; height:28px; border-radius:50%; }
        nav button { background:none; border:1px solid #3a4766; color:#c9d3ea; border-radius:6px; padding:4px 10px; cursor:pointer; }
        main { max-width:1200px; margin:0 auto; padding:20px 16px 60px; }
        h1 { font-size:22px; margin:4px 0 16px; }
        h2 { font-size:17px; margin:0 0 12px; }
        .tarjeta { background:var(--tarjeta); border:1px solid var(--borde); border-radius:10px; padding:16px; margin-bottom:16px; }
        .rejilla { display:grid; gap:16px; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
        .kpi .valor { font-size:28px; font-weight:700; font-variant-numeric: tabular-nums; }
        .kpi .etiqueta { color:var(--suave); font-size:13px; }
        table { width:100%; border-collapse:collapse; }
        th, td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--borde); vertical-align:middle; }
        th { font-size:12px; text-transform:uppercase; color:var(--suave); letter-spacing:.03em; }
        td.num { font-variant-numeric: tabular-nums; }
        .tabla-scroll { overflow-x:auto; }
        .chip { display:inline-block; padding:2px 8px; border-radius:20px; font-size:12px; font-weight:600; background:#eef1f7; }
        .chip.cobrado { background:#dff5ea; color:var(--ok); }
        .chip.sin_saldo, .chip.vehiculo_inactivo { background:#fff1d6; color:var(--aviso); }
        .chip.no_registrada { background:#fde4df; color:var(--alerta); }
        .placa { font-family: ui-monospace, Consolas, monospace; font-weight:700; letter-spacing:.06em; }
        img.mini { width:96px; height:60px; object-fit:cover; border-radius:6px; border:1px solid var(--borde); }
        input, select, textarea { font:inherit; padding:8px 10px; border:1px solid var(--borde); border-radius:8px; background:#fff; color:inherit; }
        .btn { display:inline-block; background:var(--primario); color:#fff; border:none; border-radius:8px; padding:8px 14px; cursor:pointer; font:inherit; }
        .btn.sec { background:#eef1f7; color:var(--texto); }
        .btn.peligro { background:var(--alerta); }
        .aviso-ok { background:#dff5ea; color:var(--ok); padding:10px 14px; border-radius:8px; margin-bottom:14px; }
        .aviso-error { background:#fde4df; color:var(--alerta); padding:10px 14px; border-radius:8px; margin-bottom:14px; }
        .suave { color:var(--suave); font-size:13px; }
        .fila { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        .nuevo { animation: destello 2.5s ease-out; }
        @keyframes destello { from { background:#fff6c2; } to { background:transparent; } }
        #toasts { position:fixed; right:16px; bottom:16px; display:flex; flex-direction:column; gap:8px; z-index:99; }
        .toast { background:var(--alerta); color:#fff; padding:12px 16px; border-radius:10px; box-shadow:0 6px 20px rgba(0,0,0,.2); max-width:340px; }
        .rt { font-size:12px; color:var(--suave); }
        .rt b { color:var(--ok); }
        @media (max-width:640px) { nav .usuario span { display:none; } .kpi .valor { font-size:22px; } }
    </style>
    @stack('cabecera')
</head>
<body>
@auth
<nav>
    <span class="marca">🚧 Peaje IA</span>
    <a href="{{ route('panel') }}" class="{{ request()->routeIs('panel') ? 'activo' : '' }}">Panel</a>
    <a href="{{ route('vehiculos.index') }}" class="{{ request()->routeIs('vehiculos.*') ? 'activo' : '' }}">Vehículos</a>
    <a href="{{ route('pasos') }}" class="{{ request()->routeIs('pasos') ? 'activo' : '' }}">Pasos</a>
    <a href="{{ route('alertas') }}" class="{{ request()->routeIs('alertas') ? 'activo' : '' }}">Alertas</a>
    <a href="{{ route('chat') }}" class="{{ request()->routeIs('chat') ? 'activo' : '' }}">Chat</a>
    <a href="{{ route('estado') }}" class="{{ request()->routeIs('estado') ? 'activo' : '' }}">Estado</a>
    @if(auth()->user()->esAdmin())
        <a href="{{ route('admin') }}" class="{{ request()->routeIs('admin') ? 'activo' : '' }}">Administración</a>
    @endif
    <a href="{{ route('api.docs') }}">API</a>
    <div class="usuario">
        @if(auth()->user()->avatar)<img src="{{ auth()->user()->avatar }}" alt="" referrerpolicy="no-referrer">@endif
        <span>{{ auth()->user()->nombre ?? auth()->user()->email_google }} · {{ auth()->user()->rol }}</span>
        <form method="post" action="{{ route('logout') }}">@csrf<button>Salir</button></form>
    </div>
</nav>
@endauth
<main>
    @if(session('ok'))<div class="aviso-ok">{{ session('ok') }}</div>@endif
    @if(session('error'))<div class="aviso-error">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="aviso-error">{{ $errors->first() }}</div>@endif
    @yield('contenido')
</main>
<div id="toasts"></div>

@auth
@if(config('peaje.pusher_key'))
<script src="https://cdnjs.cloudflare.com/ajax/libs/pusher/8.3.0/pusher.min.js"></script>
<script>
    // Tiempo real (Pusher, canal privado autorizado por la sesión de Laravel).
    window.peajeRT = new Pusher(@json(config('peaje.pusher_key')), {
        cluster: @json(config('peaje.pusher_cluster')),
        channelAuthorization: { endpoint: '/broadcasting/auth', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } },
    });
    window.canalPanel = window.peajeRT.subscribe('private-panel');
    window.canalPanel.bind('alerta.creada', (d) => {
        const t = document.createElement('div');
        t.className = 'toast';
        t.innerHTML = '<b>⚠ Alerta: ' + d.alerta.tipo.replace('_', ' ') + '</b><br>' + (d.alerta.detalle || '') + '<br><small>' + (d.alerta.caseta || '') + ' · ' + d.alerta.hora + '</small>';
        document.getElementById('toasts').appendChild(t);
        setTimeout(() => t.remove(), 9000);
    });
</script>
@endif
@endauth
@stack('scripts')
</body>
</html>
