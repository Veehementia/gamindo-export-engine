<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $fillable = [
        'version_id', 'external_id', 'email', 'registered_at', 'total_score', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'registered_at' => 'datetime',
        'total_score' => 'integer',
    ];
}
