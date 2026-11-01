@extends('layouts.app')

@section('content')
    <div class="card" style="max-width: 520px; margin: 4rem auto 0;">
        <h1>Rotate bootstrap password</h1>
        <p class="muted">This one-time bootstrap account cannot manage the router until its password is replaced.</p>

        <form method="POST" action="{{ route('password-rotation.update') }}" class="stack">
            @csrf
            @method('PUT')

            <div>
                <label for="current_password">Current password</label>
                <input id="current_password" type="password" name="current_password" required autofocus>
                @error('current_password') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div>
                <label for="password">New password</label>
                <input id="password" type="password" name="password" required>
                @error('password') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div>
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required>
            </div>

            <button type="submit" class="button">Rotate password</button>
        </form>
    </div>
@endsection
