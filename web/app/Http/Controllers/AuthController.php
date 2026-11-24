<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

/** Acceso exclusivo con Google (web: OAuth 2.0 con Socialite; app: ID token de Google Sign-In). */
class AuthController extends Controller
{
    public function login()
    {
        return Auth::check() ? redirect()->route('panel') : view('auth.login');
    }

    public function redirigir()
    {
        return Socialite::driver('google')->scopes(['openid', 'email', 'profile'])->redirect();
    }

    public function callback(Request $request)
    {
        try {
            $google = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            return redirect()->route('login')->with('error', 'No se pudo completar el inicio de sesión con Google.');
        }

        $usuario = $this->usuarioAutorizado($google->getEmail(), $google->getName(), $google->getId(), $google->getAvatar());
        if (! $usuario) {
            return redirect()->route('acceso-denegado')->with('email', $google->getEmail());
        }
        Auth::login($usuario, true);
        $request->session()->regenerate();

        return redirect()->intended(route('panel'));
    }

    public function denegado()
    {
        return view('auth.denegado', ['email' => session('email')]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** POST /api/v1/auth/google  {id_token}  -> token de sesión del sistema (Sanctum). */
    public function api(Request $request)
    {
        $request->validate(['id_token' => 'required|string', 'dispositivo' => 'nullable|string|max:60']);

        $info = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $request->id_token]);
        if (! $info->successful()) {
            return response()->json(['message' => 'Token de Google inválido o vencido.'], 401);
        }
        $datos = $info->json();
        $audiencias = config('peaje.google_client_ids');
        if (! in_array($datos['aud'] ?? '', $audiencias, true)
            || ! in_array($datos['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
            || ($datos['email_verified'] ?? 'false') !== 'true') {
            return response()->json(['message' => 'El token no fue emitido para esta aplicación.'], 401);
        }

        $usuario = $this->usuarioAutorizado($datos['email'], $datos['name'] ?? null, $datos['sub'] ?? null, $datos['picture'] ?? null);
        if (! $usuario) {
            return response()->json(['message' => 'Acceso denegado: la cuenta '.$datos['email'].' no está autorizada.'], 403);
        }

        $token = $usuario->createToken($request->input('dispositivo', 'app-movil'), [$usuario->rol], now()->addDays(7));

        return response()->json([
            'token' => $token->plainTextToken,
            'tipo' => 'Bearer',
            'expira' => now()->addDays(7)->toIso8601String(),
            'usuario' => $usuario->only(['id', 'email_google', 'nombre', 'rol', 'avatar']),
        ]);
    }

    /** Lista blanca: solo cuentas existentes y activas en la tabla usuarios. */
    private function usuarioAutorizado(?string $email, ?string $nombre, ?string $googleId, ?string $avatar): ?Usuario
    {
        $usuario = Usuario::whereRaw('lower(email_google) = ?', [mb_strtolower((string) $email)])->where('activo', true)->first();
        if (! $usuario) {
            return null;
        }
        $usuario->fill(['nombre' => $usuario->nombre ?: $nombre, 'google_id' => $googleId, 'avatar' => $avatar])->save();

        return $usuario;
    }
}
