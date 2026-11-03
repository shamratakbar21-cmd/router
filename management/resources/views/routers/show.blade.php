@extends('layouts.app')
@section('content')
<div class="desktop-area" id="desktop-area" aria-label="Router desktop"></div>
<div class="desktop-taskbar" id="desktop-taskbar" aria-label="Open windows" hidden></div>
<dialog id="workspace-dialog" class="desktop-dialog"></dialog>
<template id="panel-template">
<section class="desktop-window" aria-label="Router workspace">
    <div class="window-title"><svg id="panel-icon"><use href="#i-network"/></svg><strong id="panel-title">ARP</strong><span id="connection-state" class="connection-state">{{ $routerState['observed']['stale'] ? 'Stale data' : 'Connecting…' }}</span><div class="window-controls"><button type="button" id="window-minimize" title="Minimize window" aria-label="Minimize window">−</button><button type="button" id="window-maximize" title="Expand window" aria-label="Expand window">⌗</button><button type="button" id="window-close" title="Close window" aria-label="Close window">×</button></div></div>
    <div class="window-content">
        <div id="ipv6-tabs" class="module-tabs" hidden></div>
        <div id="wireguard-tabs" class="module-tabs" hidden></div>
        <div id="bridge-tabs" class="module-tabs" hidden></div>
        <div id="firewall-tabs" class="module-tabs" hidden></div>
        <div class="table-toolbar">
            <button type="button" class="tool-button" id="new-entry"><svg><use href="#i-plus"/></svg>New</button>
            <button class="tool-button" id="entry-enable" disabled>▷ Enable</button><button class="tool-button" id="entry-disable" disabled>Ⅱ Disable</button><button class="tool-button" id="entry-remove" disabled><span class="remove-icon" aria-hidden="true">×</span>Remove</button><button class="tool-button" id="entry-comment" disabled>▱ Comment</button><button class="tool-button" id="entry-move-up" hidden disabled>↑ Move Up</button><button class="tool-button" id="entry-move-down" hidden disabled>↓ Move Down</button>
            <span class="bar-spacer"></span><button class="tool-button" id="columns-open" title="Column order, widths and visibility">Columns…</button><button class="tool-button" id="refresh-table" title="Refresh from appliance"><svg><use href="#i-refresh"/></svg><span class="refresh-label">Refresh</span></button>
            <label class="table-find" title="Find in current table"><svg><use href="#i-search"/></svg><input type="search" id="table-search" placeholder="Find" aria-label="Find in current table" autocomplete="off"></label>
            <button class="tool-button" id="filter-toggle" aria-expanded="false"><svg><use href="#i-filter"/></svg>Filter</button>
        </div>
        <div class="filter-bar" id="filter-bar" hidden><label>Status <select id="status-filter"><option value="">All statuses</option></select></label><button class="tool-button" id="clear-filter">Clear filter</button></div>
        <div class="state-notice" id="state-notice" hidden role="status"></div>
        <div class="data-grid" id="data-grid" tabindex="0" aria-label="Router data"><table id="router-table"><thead></thead><tbody></tbody></table><div class="empty-state" id="empty-state" hidden></div></div>
        <div id="special-panel" class="special-panel" hidden></div>
        <div class="table-status"><span id="row-count">0</span><span id="selected-count"></span><button id="live-toggle" class="live-toggle" aria-pressed="false">▷ Live</button><span id="observed-time">Waiting for appliance state</span><span class="bar-spacer"></span><a href="{{ route('routers.desired-state.edit', $routerNode) }}" class="status-link">Configure</a><button class="tool-button" id="apply-open">Apply…</button></div>
    </div>
<dialog id="columns-dialog" class="desktop-dialog columns-dialog"></dialog>
<dialog id="row-dialog" class="desktop-dialog"><form method="dialog"><div class="dialog-title"><strong id="row-dialog-title">Entry</strong><button aria-label="Close entry">×</button></div><div id="row-dialog-body" class="dialog-body"></div><div class="dialog-actions"><button class="button" value="close">Close</button></div></form></dialog>
<dialog id="mutation-dialog" class="desktop-dialog editor-dialog"></dialog>
<dialog id="apply-dialog" class="desktop-dialog"><div class="dialog-title"><strong>Apply staged configuration</strong><button type="button" onclick="this.closest('dialog').close()" aria-label="Close apply dialog">×</button></div><div class="dialog-body"><p id="apply-revisions">Desired revision {{ $routerNode->desired_revision_id ?? 'none' }} · Applied revision {{ $routerNode->applied_revision_id ?? 'none' }}</p>
@if($routerNode->safe_mode_status === 'pending_confirm')<p>Safe Mode is pending. Confirm only after verifying connectivity.</p><form method="POST" action="{{ route('routers.safe-mode.confirm',$routerNode) }}">@csrf<button class="button">Confirm Safe Mode</button></form><form method="POST" action="{{ route('routers.safe-mode.recover', $routerNode) }}">@csrf<button class="button">Recover previous confirmed configuration</button></form>
@else<form method="POST" action="{{ route('routers.apply',$routerNode) }}">@csrf<input type="hidden" id="apply-lock-version" name="expected_lock_version" value="{{ $routerNode->configuration_lock_version }}"><label>Safe Mode rollback timeout (minutes)<input type="number" name="safe_mode_minutes" min="1" max="30" value="5" required></label><p class="muted">Unconfirmed changes roll back automatically.</p><button class="button" id="apply-revision-button" @disabled(!$routerNode->hasStagedConfiguration() || $routerNode->pending_operation_uuid)>Apply staged revision</button><p id="apply-current-note" @if($routerNode->hasStagedConfiguration()) hidden @endif>All staged changes are already applied.</p></form>@endif
</div></dialog>
</section>
</template>
<script type="application/json" id="router-desktop-state">@json($routerState)</script>
@php
    $desktopOptions = [
        'stateUrl' => route('routers.state.show', $routerNode),
        'configureUrl' => route('routers.desired-state.edit', $routerNode),
        'backupUrl' => route('routers.backup.show', $routerNode),
        'brasUrl' => route('routers.bras.edit', $routerNode),
        'routerId' => $routerNode->id,
        'userId' => auth()->id(),
        'mutationUrl' => route('routers.desktop.mutate', $routerNode),
        'diagnosticUrl' => route('routers.diagnostics', $routerNode),
    ];
@endphp
<script type="application/json" id="router-desktop-options">@json($desktopOptions)</script>
@endsection
@push('scripts')<script src="{{ asset('desktop/desktop.js') }}?v=20261104-desktop-2" defer></script><script src="{{ asset('desktop/wincontrol.js') }}?v=20261104-desktop-2" defer></script>@endpush
