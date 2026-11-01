@extends('layouts.app')

@section('content')
    <div class="card" style="max-width: 460px; margin: 6rem auto 0;">
        <h1>NUR WinControl Login</h1>
        <p class="muted">Authenticate to manage isolated NUR RouterOS namespaces.</p>

        <form method="POST" action="{{ route('login.store') }}" class="stack">
            @csrf
            <div>
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
                @error('email') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div>
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required>
                @error('password') <div class="error">{{ $message }}</div> @enderror
            </div>

            <button type="submit" class="button">Sign In</button>
        </form>
    </div>
@endsection
