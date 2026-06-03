<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Answer extends Model
{
    protected $fillable = [
        'version_id', 'player_id', 'question_id', 'answer', 'is_correct', 'occurred_at', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'is_correct' => 'boolean',
    ];
}
