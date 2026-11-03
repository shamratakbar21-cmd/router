<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = ['en', 'bn'];
        $locale = $request->session()->get('locale', config('app.locale', 'en'));

        if ($request->query->has('lang')) {
            $candidate = (string) $request->query('lang');
            if (in_array($candidate, $supported, true)) {
                $locale = $candidate;
                $request->session()->put('locale', $locale);
            }
        }

        if (! in_array($locale, $supported, true)) {
            $locale = 'en';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
