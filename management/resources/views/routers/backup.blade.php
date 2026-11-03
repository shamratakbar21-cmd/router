@extends('layouts.app')

@section('content')
    <div class="card">
        <h1>Backup / restore — {{ $routerNode->name }}</h1>
        <p class="muted">
            Versioned configuration backup. Device identity and license ownership are never included.
            Replacement hardware requires license device transfer before restore.
        </p>
        <p>
            <a class="button button-secondary" href="{{ route('routers.show', $routerNode) }}">Back</a>
        </p>
    </div>

    <div class="card">
        <h2>Export</h2>
        <form method="POST" action="{{ route('routers.backup.export', $routerNode) }}" class="stack" style="max-width: 480px;">
            @csrf
            <label><input type="checkbox" name="include_secrets" value="1"> Include encrypted secrets (one-time controlled download)</label>
            <button class="button" type="submit">Download backup JSON</button>
        </form>
    </div>

    <div class="card">
        <h2>Restore preview</h2>
        <form method="POST" action="{{ route('routers.backup.preview', $routerNode) }}" class="stack">
            @csrf
            <label>Backup JSON
                <textarea name="backup_json" rows="12" required>{{ old('backup_json', session('backup_json_pending')) }}</textarea>
            </label>
            <label><input type="checkbox" name="allow_legacy" value="1"> Import a trusted version 1 backup</label>
            <p>Encrypted backups require the original APP_KEY. Public backups contain no secrets and are validated as untrusted input.</p>
            <button class="button button-secondary" type="submit">Preview</button>
        </form>
        @if (! empty($previewErrors))
            <ul class="flash-error">
                @foreach ($previewErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
        @if (! empty($preview))
            <pre>{{ json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            <form method="POST" action="{{ route('routers.backup.restore', $routerNode) }}" class="stack" style="max-width: 480px;">
                @csrf
                <input type="hidden" name="allow_legacy" value="{{ session('backup_allow_legacy') ? 1 : 0 }}">
                <input type="hidden" name="backup_json" value="{{ session('backup_json_pending') }}">
                <label><input type="checkbox" name="confirm" value="1" required> I confirm restore onto this router (not a silent clone of device identity)</label>
                <button class="button" type="submit">Restore into desired state</button>
            </form>
        @endif
    </div>
@endsection
