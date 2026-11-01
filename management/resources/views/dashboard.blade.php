@extends('layouts.app')

@section('content')
    <div class="grid">
        <div class="card">
            <h2>Total namespaces</h2>
            <p style="font-size: 2rem;">{{ $routerNodeCount }}</p>
        </div>
        <div class="card">
            <h2>Applied namespaces</h2>
            <p style="font-size: 2rem;">{{ $appliedRouterNodeCount }}</p>
        </div>
    </div>

    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h2>Recent audit activity</h2>
            <a class="button" href="{{ route('routers.create') }}">Create router namespace</a>
        </div>
        <table class="table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Action</th>
                    <th>Summary</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recentAuditLogs as $auditLog)
                    <tr>
                        <td>{{ $auditLog->created_at }}</td>
                        <td>{{ $auditLog->action }}</td>
                        <td>{{ $auditLog->summary }}</td>
                        <td>{{ $auditLog->status }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">No actions recorded yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
