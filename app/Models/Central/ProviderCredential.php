<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class ProviderCredential extends Model
{
    protected $table = 'provider_credentials';

    protected $fillable = [
        'tenant_id',
        'provider_code',
        'credentials_json',
    ];

    protected $casts = [
        'credentials_json' => 'array', // 🔥 key fix
    ];
}
