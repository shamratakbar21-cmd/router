<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordRotation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->user()?->must_rotate_password
            && ! $request->routeIs('password-rotation.*')
            && ! $request->routeIs('logout')
        ) {
            return new RedirectResponse(route('password-rotation.edit'));
        }

        return $next($request);
    }
}
