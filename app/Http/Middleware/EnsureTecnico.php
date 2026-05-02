<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tecnico;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTecnico
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isTecnico()) {
            return redirect()->route('tecnico.login');
        }

        // Verifica que el técnico sigue activo en legacy entre peticiones.
        $tecnico = $user->legacyEntity();
        if (! $tecnico instanceof Tecnico || (int) $tecnico->status !== 1) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('tecnico.login')
                ->withErrors(['email' => 'Cuenta de técnico inactiva.']);
        }

        return $next($request);
    }
}
