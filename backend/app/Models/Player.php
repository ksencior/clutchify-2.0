<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Player extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'team_id',
        'steam_id',
        'avatar',
        'isAdmin',
        'isSpectator',
        'is_substitute',
    ];

    protected $casts = [
        'isAdmin' => 'boolean',
        'isSpectator' => 'boolean',
        'is_substitute' => 'boolean',
    ];

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }


    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
