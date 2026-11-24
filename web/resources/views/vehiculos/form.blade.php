@extends('layouts.app')
@section('titulo', $vehiculo->exists ? 'Editar vehículo' : 'Nuevo vehículo')
@section('contenido')
<h1>{{ $vehiculo->exists ? 'Editar '.$vehiculo->placa : 'Nuevo vehículo' }}</h1>
<form class="tarjeta" style="max-width:520px;display:grid;gap:12px" method="post" action="{{ $vehiculo->exists ? route('vehiculos.update', $vehiculo) : route('vehiculos.store') }}">
    @csrf
    @if($vehiculo->exists) @method('put') @endif
    <label>Placa<br><input name="placa" value="{{ old('placa', $vehiculo->placa) }}" required style="width:100%" placeholder="1234ABC"></label>
    <label>Propietario<br><input name="propietario" value="{{ old('propietario', $vehiculo->propietario) }}" required style="width:100%"></label>
    <label>Categoría<br>
        <select name="categoria" style="width:100%">
            @foreach(\App\Models\Vehiculo::CATEGORIAS as $c)<option value="{{ $c }}" @selected(old('categoria', $vehiculo->categoria) === $c)>{{ $c }}</option>@endforeach
        </select>
    </label>
    <label>Ejes<br><input type="number" name="ejes" min="2" max="9" value="{{ old('ejes', $vehiculo->ejes) }}" required style="width:100%"></label>
    <label>Saldo (Bs)<br><input type="number" step="0.01" min="0" name="saldo" value="{{ old('saldo', $vehiculo->saldo) }}" required style="width:100%"></label>
    <label class="fila"><input type="checkbox" name="activo" value="1" @checked(old('activo', $vehiculo->activo))> Activo</label>
    <div class="fila"><button class="btn">Guardar</button><a class="btn sec" href="{{ route('vehiculos.index') }}">Cancelar</a></div>
</form>
@endsection
