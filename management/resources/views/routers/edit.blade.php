@extends('layouts.app')

@section('content')
    <div class="card" style="max-width: 720px;">
        <h1>Edit {{ $routerNode->name }}</h1>
        <p class="muted">Saving creates a new staged configuration revision.</p>

        <form method="POST" action="{{ route('routers.update', $routerNode) }}" class="stack">
            @csrf
            @method('PUT')
            @include('routers._form')
            <button type="submit" class="button">Save staged revision</button>
        </form>
    </div>
@endsection
