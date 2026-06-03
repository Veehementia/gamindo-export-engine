<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Version extends Model
{
    protected $fillable = ['name', 'game', 'metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function players()
    {
        return $this->hasMany(Player::class);
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }

    public function exports()
    {
        return $this->hasMany(Export::class);
    }
}
