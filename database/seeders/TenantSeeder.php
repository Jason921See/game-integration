<?php

namespace Database\Seeders;

use App\Models\Central\ProviderCredential;
use App\Models\Central\Tenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    private const PROVIDER_NDM_PLAY   = 'NDMPlay';
    private const PROVIDER_NOVA_XLIVE = 'NovaXLive';

    public function run(): void
    {
        $tenants = $this->seedTenants();

        foreach ($tenants as $tenant) {
            $this->seedProviderCredentials($tenant);
        }

        $this->command->info('✅  Tenant + provider credential seeding complete.');
    }

    private function seedTenants(): array
    {
        $tenantDefinitions = [
            [
                'name'   => 'alpha',
                'domain' => 'alpha.central.test',
                'database' => 'tenant_alpha',  // adjust to your tenancy driver
            ],
            [
                'name'   => 'bravo',
                'domain' => 'bravo.central.test',
                'database' => 'tenant_bravo',  // adjust to your tenancy driver
            ],
        ];

        $tenants = [];

        foreach ($tenantDefinitions as $def) {
            $tenant = DB::table('tenants')->upsert(
                [
                    'id'         => Str::uuid(),   // remove if your PK is auto-increment
                    'name'       => $def['name'],
                    'database'   => $def['database'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                uniqueBy: ['name'],
                update: ['database', 'updated_at'],
            );
            $tenant = Tenant::where('name', $def['name'])->first(); // fetch the Eloquent model after upsert
            $tenant->domains()->create([
                'domain' => $def['domain'],
            ]);

            $this->command->line(
                "  Tenant <fg=cyan>{$tenant->name}</> → id={$tenant->id}"
            );

            $tenants[] = $tenant;
        }

        return $tenants;
    }

    private function seedProviderCredentials(object $tenant): void
    {
        $rows = [
            $this->buildNDMPlayRow($tenant),
            $this->buildNovaXLiveRow($tenant),
        ];

        foreach ($rows as $row) {
            ProviderCredential::updateOrCreate(
                [
                    'tenant_id'     => $row['tenant_id'],
                    'provider_code' => $row['provider_code'],
                ],
                [
                    'credentials_json' => $row['credentials_json'],
                    'updated_at'       => now(),
                ]
            );

            $this->command->line(
                "    ↳ <fg=yellow>{$row['provider_code']}</> credentials seeded for tenant <fg=cyan>{$tenant->name}</>"
            );
        }
    }

    // ---------------------------------------------------------------------------
    // NDMPlay – MD5 signature auth
    //
    // Auth flow:
    //   sign = md5(agent_code + timestamp + secret)
    //   Envelope: { code, msg, data }
    //
    // Stored credentials:
    //   agent_code  – identifies the operator/agent
    //   secret      – shared secret used in MD5 signing
    //   base_url    – API endpoint
    //   currency    – tenant's default currency on this platform
    // ---------------------------------------------------------------------------
    private function buildNDMPlayRow(object $tenant): array
    {
        // Per-tenant values – in production, load from environment or a vault.
        $credentials = match ($tenant->name) {
            'alpha' => [
                'agent_code' => Crypt::encryptString('alpha-ndmplay'),
                'secret'     => Crypt::encryptString('alpha-ndm-secret-123'),
                'base_url'   => 'http://localhost:9001/api/ndmplay',
            ],

            'bravo' => [
                'agent_code' => Crypt::encryptString('bravo-ndmplay'),
                'secret'     => Crypt::encryptString('bravo-ndm-secret-456'),
                'base_url'   => 'http://localhost:9001/api/ndmplay',
            ],

            default => throw new \RuntimeException("NDMPlay: unknown tenant [{$tenant->name}]"),
        };

        return [
            'tenant_id'        => $tenant->id,
            'provider_code'    => self::PROVIDER_NDM_PLAY,
            'credentials_json' => $credentials,
            'created_at'       => now(),
            'updated_at'       => now(),
        ];
    }

    // ---------------------------------------------------------------------------
    // NovaXLive – AES-128-CBC encrypted payload
    //
    // Transport layer:
    //   payload = AES-128-CBC(plaintext, key, iv)
    //   Request body: { dc, payload }   (dc = data-center / operator identifier)
    //
    // Stored credentials:
    //   dc   – operator / data-center code sent with every request
    //   key  – 16-byte AES-128 encryption key (hex or base64)
    //   iv   – 16-byte initialisation vector   (hex or base64)
    //   base_url  – API endpoint
    //   currency  – tenant's default currency on this platform
    // ---------------------------------------------------------------------------
    private function buildNovaXLiveRow(object $tenant): array
    {
        $credentials = match ($tenant->name) {
            'alpha' => [
                'dc'       => Crypt::encryptString('alpha-novax'),
                'key'      => Crypt::encryptString('alphaNovaXKey128'),  // 16 chars
                'iv'       => Crypt::encryptString('alphaNovaXIv_123'),  // 16 chars
                'base_url' => 'http://localhost:9001/api/novaxlive',
            ],

            'bravo' => [
                'dc'       => Crypt::encryptString('bravo-novax'),
                'key'      => Crypt::encryptString('bravoNovaXKey128'),
                'iv'       => Crypt::encryptString('bravoNovaXIv_123'),
                'base_url' => 'http://localhost:9001/api/novaxlive',
            ],

            default => throw new \RuntimeException("NovaXLive: unknown tenant [{$tenant->name}]"),
        };

        return [
            'tenant_id'        => $tenant->id,
            'provider_code'    => self::PROVIDER_NOVA_XLIVE,
            'credentials_json' => $credentials,
            'created_at'       => now(),
            'updated_at'       => now(),
        ];
    }
}
