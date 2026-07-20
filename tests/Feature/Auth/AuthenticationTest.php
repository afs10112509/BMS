<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_authenticate_via_api(): void
    {
        $user = User::factory()->owner()->create();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'role']]);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->owner()->create();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_users_can_logout_via_api(): void
    {
        $user = User::factory()->owner()->create();
        $token = $user->createToken('api-token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/auth/logout');

        $response->assertOk();
    }

    public function test_web_login_page_redirects_to_spa(): void
    {
        $this->get('/login')->assertRedirect('/app/');
    }
}
