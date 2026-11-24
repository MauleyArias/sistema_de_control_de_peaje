@extends('layouts.app')
@section('titulo', 'Administración')
@section('contenido')
<h1>Administración</h1>
<div class="rejilla" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr))">
    <div class="tarjeta">
        <h2>Lista blanca de cuentas de Google</h2>
        <table>
            <thead><tr><th>Correo</th><th>Rol</th><th>Activo</th><th></th></tr></thead>
            @foreach($usuarios as $u)
                <tr>
                    <td>{{ $u->email_google }}<br><span class="suave">{{ $u->nombre }}</span></td>
                    <td>{{ $u->rol }}</td>
                    <td>{{ $u->activo ? 'sí' : 'no' }}</td>
                    <td>
                        @if($u->id !== auth()->id())
                        <form method="post" action="{{ route('admin.usuarios.cambiar', $u) }}" class="fila">@csrf @method('patch')
                            <input type="hidden" name="activo" value="{{ $u->activo ? 0 : 1 }}">
                            <button class="btn sec">{{ $u->activo ? 'Desactivar' : 'Activar' }}</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
        <form method="post" action="{{ route('admin.usuarios') }}" class="fila" style="margin-top:12px">@csrf
            <input name="email_google" type="email" placeholder="correo@gmail.com" required>
            <select name="rol"><option value="operador">operador</option><option value="admin">admin</option></select>
            <button class="btn">Autorizar</button>
        </form>
    </div>
    <div class="tarjeta">
        <h2>Tarifas (Bs)</h2>
        <table>
            <thead><tr><th>Categoría</th><th>Ejes</th><th>Monto</th><th></th></tr></thead>
            @foreach($tarifas as $t)
                <tr><td>{{ $t->categoria }}</td><td class="num">{{ $t->ejes }}</td><td class="num">{{ number_format($t->monto, 2) }}</td>
                <td><form method="post" action="{{ route('admin.tarifas.borrar', $t) }}">@csrf @method('delete')<button class="btn sec">✕</button></form></td></tr>
            @endforeach
        </table>
        <form method="post" action="{{ route('admin.tarifas') }}" class="fila" style="margin-top:12px">@csrf
            <select name="categoria">@foreach(\App\Models\Vehiculo::CATEGORIAS as $c)<option>{{ $c }}</option>@endforeach</select>
            <input type="number" name="ejes" min="2" max="9" value="2" style="width:70px">
            <input type="number" name="monto" step="0.5" min="0" placeholder="Monto" style="width:100px" required>
            <button class="btn">Guardar</button>
        </form>
    </div>
    <div class="tarjeta">
        <h2>Casetas</h2>
        <table>
            <thead><tr><th>ID</th><th>Nombre</th><th>Ubicación</th><th>Pasos</th><th></th></tr></thead>
            @foreach($casetas as $c)
                <tr><td>{{ $c->id }}</td><td>{{ $c->nombre }}</td><td class="suave">{{ $c->ubicacion }}</td><td class="num">{{ $c->pasos_count }}</td>
                <td><form method="post" action="{{ route('admin.casetas.borrar', $c) }}">@csrf @method('delete')<button class="btn sec">✕</button></form></td></tr>
            @endforeach
        </table>
        <form method="post" action="{{ route('admin.casetas') }}" class="fila" style="margin-top:12px">@csrf
            <input name="nombre" placeholder="Nombre" required>
            <input name="ubicacion" placeholder="Ubicación">
            <button class="btn">Agregar</button>
        </form>
    </div>
</div>
@endsection
