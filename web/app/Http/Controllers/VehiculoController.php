<?php

namespace App\Http\Controllers;

use App\Models\Vehiculo;
use App\Services\PlacaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehiculoController extends Controller
{
    public function index(Request $request)
    {
        $q = Vehiculo::orderBy('placa');
        if ($request->filled('buscar')) {
            $b = $request->buscar;
            $q->where(fn ($w) => $w->where('placa', 'like', '%'.PlacaService::normalizar($b).'%')->orWhere('propietario', 'like', "%{$b}%"));
        }

        return view('vehiculos.index', ['vehiculos' => $q->paginate(25)->withQueryString()]);
    }

    public function create()
    {
        return view('vehiculos.form', ['vehiculo' => new Vehiculo(['ejes' => 2, 'activo' => true, 'saldo' => 0])]);
    }

    public function store(Request $request)
    {
        Vehiculo::create($this->validar($request));

        return redirect()->route('vehiculos.index')->with('ok', 'Vehículo registrado.');
    }

    public function edit(Vehiculo $vehiculo)
    {
        return view('vehiculos.form', compact('vehiculo'));
    }

    public function update(Request $request, Vehiculo $vehiculo)
    {
        $vehiculo->update($this->validar($request, $vehiculo));

        return redirect()->route('vehiculos.index')->with('ok', 'Vehículo actualizado.');
    }

    public function destroy(Vehiculo $vehiculo)
    {
        $vehiculo->delete();

        return redirect()->route('vehiculos.index')->with('ok', 'Vehículo eliminado.');
    }

    private function validar(Request $request, ?Vehiculo $vehiculo = null): array
    {
        $request->merge(['placa' => PlacaService::normalizar($request->placa), 'activo' => $request->boolean('activo')]);
        $datos = $request->validate([
            'placa' => ['required', 'regex:/^(?=.*\d)[A-Z0-9]{4,8}$/', Rule::unique('vehiculos', 'placa')->ignore($vehiculo)],
            'propietario' => 'required|string|max:120',
            'categoria' => ['required', Rule::in(Vehiculo::CATEGORIAS)],
            'ejes' => 'required|integer|min:2|max:9',
            'saldo' => 'required|numeric|min:0|max:100000',
            'activo' => 'boolean',
        ], ['placa.regex' => 'La placa debe tener 4 a 8 letras/números e incluir al menos un dígito.']);

        return $datos;
    }
}
