<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpanishTranslationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_laravel_messages_resolve_in_spanish(): void
    {
        app()->setLocale('es');

        $this->assertSame('Anterior', __('pagination.previous'));
        $this->assertSame('Siguiente', __('pagination.next'));
        $this->assertSame('Las credenciales ingresadas no son correctas.', __('auth.failed'));
        $this->assertSame('La contraseña es incorrecta.', __('auth.password'));
        $this->assertSame('Demasiados intentos de inicio de sesión. Inténtalo de nuevo en 60 segundos.', __('auth.throttle', ['seconds' => 60]));
        $this->assertSame('El campo correo electrónico es obligatorio.', __('validation.required', ['attribute' => 'correo electrónico']));
        $this->assertSame('El campo correo electrónico debe ser una dirección de correo válida.', __('validation.email', ['attribute' => 'correo electrónico']));
        $this->assertSame('El campo contraseña debe tener al menos 8 caracteres.', __('validation.min.string', ['attribute' => 'contraseña', 'min' => 8]));
        $this->assertSame('El campo contraseña no debe tener más de 72 caracteres.', __('validation.max.string', ['attribute' => 'contraseña', 'max' => 72]));
        $this->assertSame('La confirmación del campo contraseña no coincide.', __('validation.confirmed', ['attribute' => 'contraseña']));
        $this->assertSame('El valor del campo correo electrónico ya está en uso.', __('validation.unique', ['attribute' => 'correo electrónico']));
    }

    public function test_failed_login_uses_the_spanish_authentication_message(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'contraseña-incorrecta',
        ])->assertSessionHasErrors([
            'email' => 'Las credenciales ingresadas no son correctas.',
        ]);
    }
}
