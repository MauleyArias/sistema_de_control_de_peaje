<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Paso;
use App\Models\Usuario;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PeajeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['peaje.inference_url' => 'https://inferencia.test', 'peaje.gemini_key' => null,
            'peaje.google_client_ids' => ['web-client-id'], 'broadcasting.default' => 'null']);
    }

    private function operador(): Usuario
    {
        $u = Usuario::create(['email_google' => 'op@gmail.com', 'rol' => 'operador', 'activo' => true]);
        Sanctum::actingAs($u, ['operador']);

        return $u;
    }

    private function fakeInferencia(array $json): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);  // cada llamada reemplaza el fake anterior
        Http::fake(['inferencia.test/*' => Http::response($json + ['latencia_ms' => 200, 'evidencia_jpg_base64' => base64_encode('jpg')])]);
    }

    public function test_docente_es_admin_y_hay_20_vehiculos(): void
    {
        $this->assertDatabaseHas('usuarios', ['email_google' => 'doc.alvaro.pinto.ma@unifranz.edu.bo', 'rol' => 'admin']);
        $this->assertGreaterThanOrEqual(20, Vehiculo::count());
    }

    public function test_rutas_api_exigen_autenticacion(): void
    {
        $this->postJson('/api/v1/placas/consultar', ['placa' => '2580BOS'])->assertStatus(401);
        $this->getJson('/api/v1/casetas/1/resumen')->assertStatus(401);
        $this->postJson('/api/v1/agente/chat', ['mensaje' => 'hola'])->assertStatus(401);
    }

    public function test_auth_google_lista_blanca(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::sequence()
            ->push(['aud' => 'web-client-id', 'iss' => 'https://accounts.google.com', 'email_verified' => 'true', 'email' => 'doc.alvaro.pinto.ma@unifranz.edu.bo', 'sub' => '1'])
            ->push(['aud' => 'web-client-id', 'iss' => 'https://accounts.google.com', 'email_verified' => 'true', 'email' => 'intruso@gmail.com', 'sub' => '2'])
            ->push(['aud' => 'otra-app', 'iss' => 'https://accounts.google.com', 'email_verified' => 'true', 'email' => 'doc.alvaro.pinto.ma@unifranz.edu.bo', 'sub' => '1'])]);

        $ok = $this->postJson('/api/v1/auth/google', ['id_token' => 'x'])->assertOk()->json();
        $this->assertSame('admin', $ok['usuario']['rol']);
        $this->withToken($ok['token'])->getJson('/api/v1/yo')->assertOk()->assertJsonPath('rol', 'admin');

        $this->postJson('/api/v1/auth/google', ['id_token' => 'y'])->assertStatus(403);
        $this->postJson('/api/v1/auth/google', ['id_token' => 'z'])->assertStatus(401);
    }

    public function test_cuatro_estados_y_normalizacion(): void
    {
        $this->operador();
        $this->postJson('/api/v1/placas/consultar', ['placa' => '2580-bos'])->assertJsonPath('estado', 'registrada_con_paso')->assertJsonPath('placa', '2580BOS');
        $this->postJson('/api/v1/placas/consultar', ['placa' => '3301 SCZ'])->assertJsonPath('estado', 'registrada_sin_paso');
        $this->postJson('/api/v1/placas/consultar', ['placa' => '9090XYZ'])->assertJsonPath('estado', 'identificada_no_registrada');
        $this->postJson('/api/v1/placas/consultar', ['placa' => '1111AAA'])->assertJsonPath('estado', 'no_existe');
        // O<->0 : "258OBOS" debe sugerir 2580BOS.
        $r = $this->postJson('/api/v1/placas/consultar', ['placa' => '258OBOS'])->json();
        $this->assertSame('no_existe', $r['estado']);
        $this->assertContains('2580BOS', $r['sugerencias']);
        $this->postJson('/api/v1/placas/consultar', ['placa' => 'Â¿Â¿'])->assertJsonPath('formato_valido', false);
    }

    public function test_frame_cobra_y_descuenta_saldo(): void
    {
        $this->operador();
        $this->fakeInferencia(['placa' => '4512LPZ', 'placa_leida' => '4512-LPZ', 'confianza' => 0.93, 'categoria' => 'auto', 'ejes' => 2, 'pasajeros' => 2]);
        $r = $this->post('/api/v1/pasos/frame', ['caseta_id' => 1, 'imagen' => UploadedFile::fake()->create('f.jpg', 20, 'image/jpeg')], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertTrue($r['registrado']);
        $this->assertSame('cobrado', $r['estado']);
        $this->assertEquals(6, $r['tarifa']);
        $this->assertEquals(74, (float) Vehiculo::where('placa', '4512LPZ')->value('saldo'));
        $this->assertSame(2, Paso::latest('id')->first()->pasajeros);

        // Segundo frame del mismo vehÃ­culo: no duplica.
        $r2 = $this->post('/api/v1/pasos/frame', ['caseta_id' => 1, 'imagen' => UploadedFile::fake()->create('f.jpg', 20, 'image/jpeg')], ['Accept' => 'application/json'])->json();
        $this->assertTrue($r2['duplicado']);
        $this->assertEquals(74, (float) Vehiculo::where('placa', '4512LPZ')->value('saldo'));
    }

    public function test_alertas_no_registrada_y_sin_saldo(): void
    {
        $this->operador();
        $this->fakeInferencia(['placa' => '5555ZZZ', 'confianza' => 0.9, 'categoria' => 'camion', 'ejes' => 3, 'pasajeros' => 1]);
        $this->post('/api/v1/pasos/frame', ['caseta_id' => 1, 'imagen' => UploadedFile::fake()->create('f.jpg', 20, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertJsonPath('estado', 'no_registrada')->assertJsonPath('alerta', 'no_registrada');

        $this->fakeInferencia(['placa' => '6620PTS', 'confianza' => 0.9, 'categoria' => 'camion', 'ejes' => 5, 'pasajeros' => 1]);
        $this->post('/api/v1/pasos/frame', ['caseta_id' => 2, 'imagen' => UploadedFile::fake()->create('f.jpg', 20, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertJsonPath('estado', 'sin_saldo');
        $this->assertSame(2, Alerta::where('atendida', false)->count());
    }

    public function test_frame_sin_placa_no_registra(): void
    {
        $this->operador();
        $this->fakeInferencia(['placa' => null, 'confianza' => 0, 'categoria' => 'auto', 'ejes' => 2]);
        $this->post('/api/v1/pasos/frame', ['caseta_id' => 1, 'imagen' => UploadedFile::fake()->create('f.jpg', 20, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertJsonPath('registrado', false)->assertJsonPath('motivo', 'sin_placa_legible');
    }

    public function test_tarifa_por_ejes(): void
    {
        $this->assertEquals(45, \App\Models\Tarifa::calcular('camion', 5));
        $this->assertEquals(55, \App\Models\Tarifa::calcular('camion', 8));
        $this->assertEquals(6, \App\Models\Tarifa::calcular('auto', null));
    }

    public function test_agente_texto_e_imagen_sin_llm(): void
    {
        $this->operador();
        $this->postJson('/api/v1/agente/chat', ['mensaje' => 'Â¿QuÃ© pasa con la placa 2580-BOS?'])
            ->assertJsonPath('estado', 'registrada_con_paso')->assertJsonPath('motor', 'reglas');
        $this->postJson('/api/v1/agente/chat', ['mensaje' => 'placa 258OBOS'])
            ->assertJsonPath('estado', 'no_existe')->assertSee('Quisiste decir');

        $this->fakeInferencia(['placa' => '3301SCZ', 'confianza' => 0.88, 'placa_detectada' => true]);
        $this->post('/api/v1/agente/chat', ['imagen' => UploadedFile::fake()->create('p.jpg', 20, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertJsonPath('estado', 'registrada_sin_paso');

        $this->fakeInferencia(['placa' => null, 'confianza' => 0, 'placa_detectada' => false]);
        $this->post('/api/v1/agente/chat', ['imagen' => UploadedFile::fake()->create('p.jpg', 20, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertJsonPath('estado', null)->assertSee('otra foto');
    }

    public function test_web_requiere_login_y_rol(): void
    {
        $this->get('/panel')->assertRedirect('/login');
        $op = Usuario::create(['email_google' => 'op2@gmail.com', 'rol' => 'operador', 'activo' => true]);
        $this->actingAs($op)->get('/panel')->assertOk()->assertSee('Panel en tiempo real');
        $this->actingAs($op)->get('/admin')->assertStatus(403);
        $this->actingAs($op)->get('/vehiculos')->assertOk()->assertSee('2580BOS');
        $admin = Usuario::where('rol', 'admin')->first();
        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->post('/vehiculos', ['placa' => '12-34 xyz', 'propietario' => 'Test', 'categoria' => 'auto', 'ejes' => 2, 'saldo' => 10, 'activo' => 1])->assertRedirect('/vehiculos');
        $this->assertDatabaseHas('vehiculos', ['placa' => '1234XYZ']);
    }
}
