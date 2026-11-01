@extends('layouts.app')

@section('content')
    <div class="card">
        <h1>Offline activation request</h1>
        <p class="muted">Copy this request to an internet-connected licensing host. Paste the signed response back onto the appliance.</p>
        <a class="button button-secondary" href="{{ route('licensing.status', $routerNode) }}">Back</a>
    </div>
    <div class="card">
        <pre>{{ json_encode($offlineRequest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
@endsection
