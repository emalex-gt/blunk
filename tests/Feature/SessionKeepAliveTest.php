<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SessionKeepAliveTest extends TestCase
{
    use RefreshDatabase;

    public function test_keep_alive_requires_authentication(): void
    {
        $this->get(route('session.keep-alive'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_keep_alive_returns_fresh_csrf_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('session.keep-alive'))
            ->assertOk()
            ->assertJson([
                'ok' => true,
            ])
            ->assertJsonStructure([
                'ok',
                'csrf_token',
                'expires_in',
            ]);
    }

    public function test_unauthenticated_keep_alive_json_returns_unauthorized(): void
    {
        $this->getJson(route('session.keep-alive'))
            ->assertUnauthorized();
    }

    public function test_token_mismatch_returns_friendly_json_for_inertia_requests(): void
    {
        Route::post('/__testing/session-expired', function () {
            throw new TokenMismatchException();
        })->middleware('web');

        $this->withHeaders([
            'Accept' => 'application/json',
            'X-Inertia' => 'true',
        ])
            ->post('/__testing/session-expired')
            ->assertStatus(419)
            ->assertJson([
                'message' => 'Por seguridad, tu sesión expiró. Inicia sesión nuevamente para continuar.',
                'session_expired' => true,
            ]);
    }
}
