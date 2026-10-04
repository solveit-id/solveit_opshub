<?php

namespace Database\Seeders;

use App\Application\ClientTemplates\TemplateGovernance;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class ClientTemplateSeeder extends Seeder
{
    public function run(): void
    {
        Organization::where('is_active', true)->each(fn ($org) => app(TemplateGovernance::class)->seed($org));
    }
}
