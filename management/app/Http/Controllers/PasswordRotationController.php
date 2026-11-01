<?php

namespace App\Http\Controllers;

use App\Http\Requests\RotatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PasswordRotationController extends Controller
{
    public function edit(): View
    {
        return view('auth.rotate-password');
    }

    public function update(RotatePasswordRequest $request): RedirectResponse
    {
        $request->user()?->forceFill([
            'password' => (string) $request->validated('password'),
            'must_rotate_password' => false,
            'password_rotated_at' => now(),
        ])->save();

        return redirect()
            ->route('dashboard')
            ->with('status', 'Password rotated successfully.');
    }
}
