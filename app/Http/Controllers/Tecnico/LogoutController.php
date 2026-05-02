<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tecnico;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class LogoutController
{
    public function __invoke(Request $request): RedirectResponse
    {
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('tecnico.login');
    }
}
