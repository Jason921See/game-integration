<?php

namespace App\Models\Central;

use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

class Domain extends BaseDomain
{
    protected $fillable = [
        'id',
        'tenant_id',
        'domain',
        'created_at',
        'updated_at',
    ];
}
