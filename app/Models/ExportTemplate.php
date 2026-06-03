<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportTemplate extends Model
{
    protected $fillable = ['version_id', 'name', 'description', 'definition'];

    protected $casts = [
        'definition' => 'array',
    ];
}
