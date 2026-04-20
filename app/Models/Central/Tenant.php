<?php

namespace App\Models\Central;

use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant  implements TenantWithDatabase
{
    protected $connection = 'central';
    use HasDatabase, HasDomains;
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'tenancy_db_name',
        'data',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($tenant) {
            if (empty($tenant->id)) {
                $tenant->id = Str::ulid();
            }

            if (isset($tenant->name)) {
                $tenant->attributes['name'] = $tenant->name;
            }
        });
    }

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'tenancy_db_name',
        ];
    }
}
