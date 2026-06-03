<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'version_id', 'player_id', 'amount', 'currency', 'status', 'occurred_at', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'amount' => 'integer',
    ];
}
