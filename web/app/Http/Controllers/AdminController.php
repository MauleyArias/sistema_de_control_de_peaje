<?php

namespace App\Http\Controllers;

use App\Models\Caseta;
use App\Models\Tarifa;
use App\Models\Usuario;
use App\Models\Vehiculo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tarifas, casetas y lista blanca de cuentas de Google (solo administrador). */
class AdminController extends Controller
{
    public function index()
    {
        return view('admin', [
            'tarifas' => Tarifa::orderByRaw("CASE categoria WHEN 'moto' THEN 1 WHEN 'auto' THEN 2 WHEN 'minibus' THEN 3 WHEN 'camion' THEN 4 ELSE 5 END")->orderBy('ejes')->get(),
            'casetas' => Caseta::withCount('pasos')->orderBy('id')->get(),
            'usuarios' => Usuario::orderBy('rol')->orderBy('email_google')->get(),
        ]);
    }

    public function tarifaGuardar(Request $request)
    {
        $d = $request->validate([
            'categoria' => ['required', Rule::in(Vehiculo::CATEGORIAS)],
            'ejes' => 'required|integer|min:2|max:9',
            'monto' => 'required|numeric|min:0|max:10000',
        ]);
        Tarifa::updateOrCreate(['categoria' => $d['categoria'], 'ejes' => $d['ejes']], ['monto' => $d['monto']]);

        return back()->with('ok', 'Tarifa guardada.');
    }

    public function tarifaBorrar(Tarifa $tarifa)
    {
        $tarifa->delete();

        return back()->with('ok', 'Tarifa eliminada.');
    }

    public function casetaGuardar(Request $request)
    {
        $d = $request->validate(['id' => 'nullable|exists:casetas,id', 'nombre' => 'required|string|max:80', 'ubicacion' => 'nullable|string|max:120']);
        Caseta::updateOrCreate(['id' => $d['id'] ?? null], ['nombre' => $d['nombre'], 'ubicacion' => $d['ubicacion'] ?? null]);

        return back()->with('ok', 'Caseta guardada.');
    }

    public function casetaBorrar(Caseta $caseta)
    {
        if ($caseta->pasos()->exists()) {
            return back()->with('error', 'La caseta tiene pasos registrados; no se puede eliminar.');
        }
        $caseta->delete();

        return back()->with('ok', 'Caseta eliminada.');
    }

    public function usuarioGuardar(Request $request)
    {
        $d = $request->validate([
            'email_google' => 'required|email|max:150|unique:usuarios,email_google',
            'nombre' => 'nullable|string|max:120',
            'rol' => ['required', Rule::in(['admin', 'operador'])],
        ]);
        Usuario::create($d + ['activo' => true]);

        return back()->with('ok', 'Cuenta de Google autorizada.');
    }

    public function usuarioCambiar(Request $request, Usuario $usuario)
    {
        $d = $request->validate(['rol' => ['nullable', Rule::in(['admin', 'operador'])], 'activo' => 'nullable|boolean']);
        if ($usuario->id === $request->user()->id) {
            return back()->with('error', 'No puedes cambiar tu propia cuenta.');
        }
        if (strcasecmp($usuario->email_google, 'doc.alvaro.pinto.ma@unifranz.edu.bo') === 0) {
            return back()->with('error', 'La cuenta del docente debe seguir como administrador activo.');
        }
        $usuario->update(array_filter($d, fn ($v) => $v !== null));
        if (isset($d['activo']) && ! $d['activo']) {
            $usuario->tokens()->delete();
        }

        return back()->with('ok', 'Usuario actualizado.');
    }
}
