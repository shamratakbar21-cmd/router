@extends('layouts.app')

@section('content')
    <div class="card" style="max-width: 720px;">
        <h1>Create router namespace</h1>
        <p class="muted">This stages a configuration revision. Nothing touches production interfaces.</p>

        <form method="POST" action="{{ route('routers.store') }}" class="stack">
            @csrf
            @include('routers._form')
            <button type="submit" class="button">Create namespace</button>
        </form>
    </div>
@endsection
