@extends('layouts.app')

@section('content')
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1>Router namespaces</h1>
                <p class="muted">Each router runs only inside a disposable Linux network namespace.</p>
            </div>
            <a class="button" href="{{ route('routers.create') }}">New namespace</a>
        </div>
    </div>

    <div class="card">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Namespace</th>
                    <th>Management</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($routerNodes as $routerNode)
                    <tr>
                        <td>{{ $routerNode->name }}</td>
                        <td>{{ $routerNode->namespace_name }}</td>
                        <td>{{ $routerNode->management_interface }} / {{ $routerNode->management_cidr }}</td>
                        <td>{{ $routerNode->status }}</td>
                        <td><a href="{{ route('routers.show', $routerNode) }}">Open</a></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">No router namespaces defined yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
