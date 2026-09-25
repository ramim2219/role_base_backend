<?php

namespace Database\Seeders;

use App\Models\CompanyType;
use Illuminate\Database\Seeder;

class CompanyTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = ['IT', 'Retail', 'Manufacturing', 'Service', 'Education'];

        foreach ($types as $name) {
            CompanyType::updateOrCreate(['name' => $name]);
        }
    }
}