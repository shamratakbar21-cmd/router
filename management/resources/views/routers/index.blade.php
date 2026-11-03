@extends('layouts.app')

@section('content')
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
            <div>
                <h1>{{ __('wincontrol.router_namespaces') }}</h1>
                <p class="muted">{{ __('wincontrol.router_namespaces_help') }}</p>
            </div>
            <a class="button" href="{{ route('routers.create') }}">{{ __('wincontrol.new_namespace') }}</a>
        </div>
    </div>

    <div class="card">
        <form method="GET" action="{{ route('routers.index') }}" class="toolbar" style="align-items: end;">
            <div style="min-width: 220px; flex: 1;">
                <label for="q">{{ __('wincontrol.search') }}</label>
                <input id="q" name="q" value="{{ $filters['q'] }}" placeholder="name, namespace, CIDR, host">
            </div>
            <div style="min-width: 160px;">
                <label for="status">{{ __('wincontrol.filter_status') }}</label>
                <select id="status" name="status">
                    <option value="">{{ __('wincontrol.all_statuses') }}</option>
                    @foreach ($statusOptions as $statusOption)
                        <option value="{{ $statusOption }}" @selected($filters['status'] === $statusOption)>{{ $statusOption }}</option>
                    @endforeach
                </select>
            </div>
            <div style="min-width: 160px;">
                <label for="sort">{{ __('wincontrol.sort_by') }}</label>
                <select id="sort" name="sort">
                    @foreach (['name' => 'Name', 'namespace_name' => 'Namespace', 'status' => 'Status', 'updated_at' => 'Updated'] as $value => $label)
                        <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div style="min-width: 120px;">
                <label for="direction">Dir</label>
                <select id="direction" name="direction">
                    <option value="asc" @selected($filters['direction'] === 'asc')>asc</option>
                    <option value="desc" @selected($filters['direction'] === 'desc')>desc</option>
                </select>
            </div>
            <button class="button" type="submit">{{ __('wincontrol.search') }}</button>
        </form>

        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('wincontrol.name') }}</th>
                    <th>{{ __('wincontrol.namespace') }}</th>
                    <th>{{ __('wincontrol.management') }}</th>
                    <th>{{ __('wincontrol.status') }}</th>
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
                        <td><a href="{{ route('routers.show', $routerNode) }}">{{ __('wincontrol.open') }}</a></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">{{ __('wincontrol.no_routers') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top: 1rem;">
            {{ $routerNodes->links() }}
        </div>
    </div>
@endsection
