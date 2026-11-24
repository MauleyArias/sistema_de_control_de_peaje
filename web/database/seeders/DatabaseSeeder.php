<?php

namespace Database\Seeders;

use App\Models\Alerta;
use App\Models\Caseta;
use App\Models\Paso;
use App\Models\Tarifa;
use App\Models\Usuario;
use App\Models\Vehiculo;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Lista blanca: docente (siempre admin) + ADMIN_EMAILS / OPERADOR_EMAILS del .env.
        foreach (config('peaje.admin_emails') as $email) {
            Usuario::updateOrCreate(['email_google' => strtolower($email)], ['rol' => 'admin', 'activo' => true]);
        }
        foreach (config('peaje.operador_emails') as $email) {
            Usuario::firstOrCreate(['email_google' => strtolower($email)], ['rol' => 'operador', 'activo' => true]);
        }

        if (Caseta::exists()) {
            return; // Datos de prueba solo la primera vez.
        }

        $norte = Caseta::create(['nombre' => 'Caseta Norte', 'ubicacion' => 'Carretera La Paz - Oruro, km 12']);
        $sur = Caseta::create(['nombre' => 'Caseta Sur', 'ubicacion' => 'Carretera Cochabamba - Santa Cruz, km 8']);

        foreach ([
            ['moto', 2, 3], ['auto', 2, 6], ['minibus', 2, 10],
            ['camion', 2, 15], ['camion', 3, 25], ['camion', 4, 35], ['camion', 5, 45], ['camion', 6, 55],
            ['bus', 2, 20], ['bus', 3, 30],
        ] as [$categoria, $ejes, $monto]) {
            Tarifa::create(compact('categoria', 'ejes', 'monto'));
        }

        // 22 vehículos de prueba (formato boliviano 1234ABC). Algunos sin saldo o inactivos para probar alertas.
        $vehiculos = [
            ['2580BOS', 'Ana Quispe', 'auto', 2, 150],
            ['4512LPZ', 'Carlos Mamani', 'auto', 2, 80],
            ['3301SCZ', 'Lucía Vargas', 'auto', 2, 4],
            ['1188CBA', 'Jorge Rojas', 'moto', 2, 20],
            ['5067ORU', 'Transporte Andino SRL', 'camion', 3, 400],
            ['6620PTS', 'Fletes del Sur', 'camion', 5, 30],
            ['2251TJA', 'Sindicato Mixto 14', 'minibus', 2, 60],
            ['3874BNI', 'Flota Bolívar', 'bus', 3, 500],
            ['1500PND', 'Marta Choque', 'auto', 2, 0],
            ['4096LPZ', 'Diego Flores', 'auto', 2, 95],
            ['2718SCZ', 'Rosa Gutiérrez', 'moto', 2, 12],
            ['3141CBA', 'Logística Valle', 'camion', 2, 200],
            ['1618ORU', 'Pedro Condori', 'minibus', 2, 45],
            ['4444BOS', 'Flota Trans Copacabana', 'bus', 2, 300],
            ['2023LPZ', 'Silvia Torrez', 'auto', 2, 70],
            ['1357SCZ', 'Mauricio Paz', 'auto', 2, 0],
            ['8642CBA', 'Transportes Illimani', 'camion', 4, 600],
            ['1029TJA', 'Elena Cruz', 'auto', 2, 55],
            ['5511PTS', 'Hugo Arce', 'moto', 2, 9],
            ['3690BNI', 'Cooperativa Trinidad', 'minibus', 2, 120],
            ['7777LPZ', 'Javier Salinas', 'auto', 2, 35, false],
            ['2468ORU', 'Mixtos Oruro', 'camion', 6, 900],
        ];
        foreach ($vehiculos as $v) {
            Vehiculo::create(['placa' => $v[0], 'propietario' => $v[1], 'categoria' => $v[2], 'ejes' => $v[3], 'saldo' => $v[4], 'activo' => $v[5] ?? true]);
        }

        // Historial: algunos vehículos con pasos, otros sin pasos, y una placa identificada no registrada.
        $historial = [
            ['2580BOS', 'auto', 2, 6, 'cobrado', $norte, 3],
            ['2580BOS', 'auto', 2, 6, 'cobrado', $sur, 1],
            ['4512LPZ', 'auto', 2, 6, 'cobrado', $norte, 2],
            ['5067ORU', 'camion', 3, 25, 'cobrado', $sur, 2],
            ['3874BNI', 'bus', 3, 30, 'cobrado', $norte, 1],
            ['2251TJA', 'minibus', 2, 10, 'cobrado', $sur, 4],
            ['1500PND', 'auto', 2, 0, 'sin_saldo', $norte, 2],
            ['9090XYZ', 'auto', 2, 0, 'no_registrada', $sur, 1],
        ];
        foreach ($historial as [$placa, $cat, $ejes, $monto, $estado, $caseta, $diasAtras]) {
            $fecha = now()->subDays($diasAtras)->setTime(rand(7, 19), rand(0, 59));
            $paso = new Paso([
                'placa_leida' => $placa, 'placa_normalizada' => $placa, 'confianza_ocr' => 0.9,
                'categoria_detectada' => $cat, 'ejes_detectados' => $ejes, 'pasajeros' => rand(1, 3), 'monto' => $monto,
                'caseta_id' => $caseta->id, 'estado' => $estado, 'latencia_ms' => rand(250, 600),
            ]);
            $paso->created_at = $paso->updated_at = $fecha;
            $paso->save();
            if ($estado !== 'cobrado') {
                $alerta = new Alerta(['paso_id' => $paso->id, 'tipo' => $estado, 'detalle' => 'Dato de prueba', 'atendida' => true]);
                $alerta->created_at = $alerta->updated_at = $fecha;
                $alerta->save();
            }
        }
    }
}
