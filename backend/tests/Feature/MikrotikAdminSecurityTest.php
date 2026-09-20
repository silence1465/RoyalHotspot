<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MikrotikAdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_token_cannot_bypass_router_management_lock(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('locked-admin', ['admin'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/routers')
            ->assertStatus(423)
            ->assertJsonPath('code', 'MIKROTIK_ADMIN_LOCKED');
    }

    public function test_valid_key_unlocks_only_the_current_admin_token_and_lock_revokes_it(): void
    {
        config(['mikrotik_security.key_hash' => Hash::make('correct-secure-key')]);
        $admin = User::factory()->admin()->create();
        $firstToken = $admin->createToken('first-admin', ['admin'])->plainTextToken;
        $secondAdmin = User::factory()->admin()->create();
        $secondToken = $secondAdmin->createToken('second-admin', ['admin'])->plainTextToken;

        $this->withToken($firstToken)->postJson('/api/v1/admin/mikrotik-security/unlock', [
            'security_key' => 'correct-secure-key',
        ])->assertOk()->assertJsonPath('unlocked', true);

        $this->withToken($firstToken)->getJson('/api/v1/admin/routers')->assertOk();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withToken($secondToken)->getJson('/api/v1/admin/routers')->assertStatus(423);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withToken($firstToken)->postJson('/api/v1/admin/mikrotik-security/lock')->assertOk();
        $this->withToken($firstToken)->getJson('/api/v1/admin/routers')->assertStatus(423);
    }

    public function test_failed_unlock_is_generic_and_audited(): void
    {
        config(['mikrotik_security.key_hash' => Hash::make('correct-secure-key')]);
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('failed-unlock', ['admin'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/admin/mikrotik-security/unlock', [
            'security_key' => 'wrong-key',
        ])->assertStatus(422)->assertJson(['message' => 'The security key is invalid.']);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'mikrotik.security.unlock_failed',
        ]);
    }

    public function test_management_inputs_reject_unscoped_or_command_like_values_before_router_access(): void
    {
        config([
            'mikrotik_security.key_hash' => Hash::make('correct-secure-key'),
            'mikrotik_security.allowed_address_lists' => ['royal-hotspot-allow'],
            'mikrotik_security.protected_interfaces' => ['ether1', 'wireguard1'],
        ]);
        $admin = User::factory()->admin()->create();
        $router = Router::factory()->create(['connection_mode' => 'live']);
        $admin->routers()->attach($router);
        $token = $admin->createToken('validation-admin', ['admin'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/admin/mikrotik-security/unlock', [
            'security_key' => 'correct-secure-key',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/admin/router-management/'.$router->id.'/address-lists', [
            'list' => 'unrestricted-firewall-list',
            'address' => '10.0.0.1',
        ])->assertUnprocessable();

        $this->withToken($token)->postJson('/api/v1/admin/router-management/'.$router->id.'/bridge-ports', [
            'bridge' => 'bridge-hotspot',
            'interface' => 'ether1',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'This interface is protected from bridge changes.');

        $this->withToken($token)->postJson('/api/v1/admin/router-management/'.$router->id.'/queues', [
            'name' => 'unsafe',
            'target' => '10.0.0.1; /system reset-configuration',
        ])->assertUnprocessable();

        $this->withToken($token)->postJson('/api/v1/admin/router-management/'.$router->id.'/terminal', [
            'command' => '/system reset-configuration',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Only a super administrator can change router configuration from the terminal.');

        $this->withToken($token)->patchJson('/api/v1/admin/router-management/'.$router->id.'/bindings/*1/status', [
            'active' => 'not-a-boolean',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('active');
    }
}
