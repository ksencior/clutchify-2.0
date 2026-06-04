<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            // Dodajemy znacznik rezerwowego (domyślnie false - czyli główny skład)
            $table->boolean('is_substitute')->default(false)->after('team_id');

            // Zapinamy w końcu bezpieczny klucz obcy (jeśli drużyna się rozpadnie, team_id graczy zmieni się na NULL)
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn('is_substitute');
        });
    }
};
