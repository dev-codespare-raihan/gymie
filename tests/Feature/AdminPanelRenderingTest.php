<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

it('renders the admin login page', function (): void {

    $this->get(route('filament.admin.auth.login'))
        ->assertOk()
        ->assertSee('Codespare Technologies');
});

it('prevents inactive users from accessing the admin panel', function (): void {
    $user = User::factory()->inactive()->create();

    $this->actingAs($user)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertForbidden();
});

it('allows active users to access the admin panel', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertOk();
});

it('shows software configuration only to super admins', function (): void {
    Role::findOrCreate('super_admin', 'web');
    $user = User::factory()->create()->assignRole('super_admin');

    $this->actingAs($user)
        ->get(route('filament.admin.pages.settings'))
        ->assertOk()
        ->assertSee('Software Configuration')
        ->assertSee('Clear application caches')
        ->assertSee('Optimize application');
});
