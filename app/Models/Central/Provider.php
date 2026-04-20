<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    protected $fillable = ['name', 'code', 'setting', 'is_active'];

    protected $casts = [
        'setting'  => 'array',
        'is_active' => 'boolean',
    ];
}
