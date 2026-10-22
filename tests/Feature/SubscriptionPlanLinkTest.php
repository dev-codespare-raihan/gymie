<?php

use App\Filament\Resources\Plans\PlanResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('renders the subscriptions page when no plans exist', function (): void {
    $this->withoutVite();
    Role::findOrCreate('super_admin', 'web');

    $this->actingAs(User::factory()->create()->assignRole('super_admin'))
        ->get(route('filament.admin.resources.subscriptions.index'))
        ->assertOk()
        ->assertSee(PlanResource::getUrl('index'));
});
