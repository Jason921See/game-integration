<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Stancl\Tenancy\Events\DatabaseMigrated;

class CreateTenantUser
{
    public function handle(DatabaseMigrated $event): void
    {
        $tenant = $event->tenant;
        $centralConnection = config('tenancy.database.central_connection');

        $users = [
            [
                'name' => 'Tenant Admin',
                'email' => 'admin@gmail.com',
                'password' => 'password',
            ],
            [
                'name' => 'Tenant User',
                'email' => 'user@gmail.com',
                'password' => 'password',
            ],
        ];

        $centralDb = DB::connection($centralConnection);

        tenancy()->initialize($tenant);

        try {
            foreach ($users as $user) {

                $userData = [
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'email_verified_at' => now(),
                    'password' => Hash::make($user['password']),
                    'remember_token' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // ─────────────────────────────
                // CENTRAL USER
                // ─────────────────────────────

                $centralUser = $centralDb->table('users')
                    ->where('email', $user['email'])
                    ->first();

                if ($centralUser) {
                    $globalId = $centralUser->global_id;
                } else {
                    $globalId = Str::ulid();

                    $centralDb->table('users')->insert(array_merge($userData, [
                        'global_id' => $globalId,
                    ]));
                }

                // ─────────────────────────────
                // TENANT USER
                // ─────────────────────────────

                DB::table('users')->updateOrInsert(
                    ['email' => $user['email']],
                    array_merge($userData, [
                        'global_id' => $globalId,
                    ])
                );
            }
        } finally {
            tenancy()->end();
        }
    }
}
