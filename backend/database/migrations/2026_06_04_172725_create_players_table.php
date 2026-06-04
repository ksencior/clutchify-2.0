<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            // Powiązanie z tabelą users (jeśli user zostanie usunięty, profil gracza też zniknie)
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('team_id')->nullable();
            
            // Dodatkowe zmienne dla Clutchify v2
            $table->string('steam_id')->nullable()->unique();
            $table->string('avatar')->nullable();
            
            $table->boolean('isAdmin')->default(false);
            $table->boolean('isSpectator')->default(false);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
