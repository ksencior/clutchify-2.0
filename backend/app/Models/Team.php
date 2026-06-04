<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'tag',
        'logo',
        'captian_id'
    ];

    public function players(): HasMany {
        return $this->hasMany(Player::class);
    }

    public function captian(): BelongsTo {
        return $this->belongsTo(Player::class, 'captain_id');
    }
}
