<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lista blanca: solo estas cuentas de Google pueden entrar. Sin contraseñas propias.
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('email_google')->unique();
            $table->string('nombre')->nullable();
            $table->enum('rol', ['admin', 'operador'])->default('operador');
            $table->boolean('activo')->default(true);
            $table->string('google_id')->nullable();
            $table->string('avatar')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
        Schema::dropIfExists('sessions');
    }
};
