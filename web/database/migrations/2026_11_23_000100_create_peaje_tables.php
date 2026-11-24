<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('casetas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('ubicacion')->nullable();
            $table->timestamps();
        });

        Schema::create('tarifas', function (Blueprint $table) {
            $table->id();
            $table->enum('categoria', ['moto', 'auto', 'minibus', 'camion', 'bus']);
            $table->unsignedTinyInteger('ejes');
            $table->decimal('monto', 10, 2);
            $table->unique(['categoria', 'ejes']);
            $table->timestamps();
        });

        Schema::create('vehiculos', function (Blueprint $table) {
            $table->id();
            $table->string('placa', 12)->unique();
            $table->string('propietario');
            $table->enum('categoria', ['moto', 'auto', 'minibus', 'camion', 'bus']);
            $table->unsignedTinyInteger('ejes')->default(2);
            $table->decimal('saldo', 10, 2)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('pasos', function (Blueprint $table) {
            $table->id();
            $table->string('placa_leida', 20)->nullable();
            $table->string('placa_normalizada', 12)->nullable()->index();
            $table->decimal('confianza_ocr', 5, 3)->nullable();
            $table->string('categoria_detectada', 20)->nullable();
            $table->unsignedTinyInteger('ejes_detectados')->nullable();
            $table->unsignedTinyInteger('pasajeros')->nullable();
            $table->decimal('monto', 10, 2)->default(0);
            $table->foreignId('caseta_id')->constrained('casetas');
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('imagen_url')->nullable();
            // cobrado | sin_saldo | no_registrada | vehiculo_inactivo
            $table->string('estado', 30);
            $table->unsignedInteger('latencia_ms')->nullable();
            $table->timestamps();
        });

        // Las imágenes de evidencia se guardan en la BD: el disco de Render es efímero.
        Schema::create('evidencias', function (Blueprint $table) {
            $table->id();
            $table->string('mime', 30)->default('image/jpeg');
            $table->longText('datos_base64');
            $table->timestamps();
        });

        Schema::create('alertas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paso_id')->constrained('pasos')->cascadeOnDelete();
            // no_registrada | sin_saldo | vehiculo_inactivo
            $table->string('tipo', 30);
            $table->string('detalle')->nullable();
            $table->boolean('atendida')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas');
        Schema::dropIfExists('evidencias');
        Schema::dropIfExists('pasos');
        Schema::dropIfExists('vehiculos');
        Schema::dropIfExists('tarifas');
        Schema::dropIfExists('casetas');
    }
};
