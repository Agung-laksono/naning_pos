<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_cashiers_can_authenticate_using_their_name(): void
    {
        $user = User::factory()->create([
            'name' => 'Siska Anggraeni',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post('/login', [
            'name' => 'Siska Anggraeni',
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('pos.index', absolute: false));
    }

    public function test_cashiers_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'name' => 'Siska Anggraeni',
            'password' => bcrypt('password123'),
        ]);

        $this->post('/login', [
            'name' => 'Siska Anggraeni',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_cashiers_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_unauthenticated_user_accessing_pos_is_redirected_to_login(): void
    {
        $response = $this->get('/pos');

        $response->assertRedirect(route('login'));
    }

    public function test_customer_facing_display_can_be_accessed_without_login(): void
    {
        $response = $this->get('/pos/display');

        $response->assertStatus(200);
    }
}
