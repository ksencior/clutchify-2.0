<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    // Pobieranie listy wszystkich drużyn z ilością graczy
    public function index()
    {
        // withCount('players') automatycznie dodaje pole 'players_count' do wyniku JSON
        $teams = Team::withCount('players')->latest()->get();
        return response()->json($teams);
    }

    // Tworzenie nowej drużyny
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:teams,name',
            'tag' => 'required|string|max:10|unique:teams,tag',
        ]);

        $user = $request->user();
        $player = $user->player;

        // Zabezpieczenie: czy gracz już ma drużynę?
        if ($player->team_id) {
            return response()->json(['message' => 'Należysz już do innej drużyny.'], 400);
        }

        // Używamy transakcji - jeśli coś w środku się zepsuje, baza wycofa zmiany
        return DB::transaction(function () use ($request, $player) {
            // 1. Tworzymy drużynę i od razu przypisujemy twórcę jako kapitana
            $team = Team::create([
                'name' => $request->name,
                'tag' => strtoupper($request->tag),
                'captain_id' => $player->id,
            ]);

            // 2. Aktualizujemy profil gracza
            $player->update([
                'team_id' => $team->id,
                'is_substitute' => false, // Trafia do głównego składu
            ]);

            return response()->json($team->loadCount('players'), 201);
        });
    }
}