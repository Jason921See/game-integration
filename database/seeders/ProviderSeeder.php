<?php

namespace Database\Seeders;

use App\Models\Central\Provider;
use Illuminate\Database\Seeder;

class ProviderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $providers = [
            [
                'code'      => 'novax_live',
                'name'     => 'NovaX Live',
                'is_active' => true,
                'setting'  => [
                    'base_url'   => 'http://localhost:9000/api', // example URL, adjust as needed
                ],
            ],
            [
                'code'      => 'ndm_play',
                'name'     => 'NDM Play',
                'is_active' => true,
                'setting'  => [
                    'base_url'   => 'http://localhost:9001/api',
                ],
            ],
        ];

        foreach ($providers as $data) {
            Provider::updateOrCreate(
                ['code' => $data['code']],
                $data,
            );
        }
    }
}
