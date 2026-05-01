<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Auth\LegacyHashGuard;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;

/**
 * Login page del panel admin.
 *
 * Override `authenticate()` para delegar en `LegacyHashGuard` con role='admin'.
 * El form schema, layout y middlewares se heredan intactos del BaseLogin.
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();

        // ValidationException (throttle u otra) se propaga para que Filament
        // muestre el error en el form. No la capturamos.
        $ok = app(LegacyHashGuard::class)->attempt(
            email: $data['email'],
            password: $data['password'],
            roleHint: 'admin',
            request: request(),
        );

        if (! $ok) {
            $this->throwFailureValidationException();
        }

        return app(LoginResponse::class);
    }
}
