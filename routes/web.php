<?php

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

Route::domain('{tenant}.' . config('app.domain'))->middleware([
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::get('/', function () {
        return view('tenant.welcome');
    });
});


// Central web routes (no tenancy middleware)
Route::domain(config('app.domain'))
    ->group(function () {
        Route::get('/', fn() => view('central.welcome'));
    });
