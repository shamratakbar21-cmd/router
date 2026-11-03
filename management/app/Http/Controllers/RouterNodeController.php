<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRouterNodeRequest;
use App\Http\Requests\UpdateRouterNodeRequest;
use App\Models\RouterNode;
use App\Services\Licensing\LicenseEnforcement;
use App\Services\RouterCapacity;
use App\Services\RouterConfigurationManager;
use App\Services\RouterStateProjector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RouterNodeController extends Controller
{
    public function __construct(
        private readonly RouterConfigurationManager $configurationManager,
        private readonly RouterStateProjector $stateProjector,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', RouterNode::class);

        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $sort = (string) $request->query('sort', 'name');
        $direction = strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $sortable = ['name', 'namespace_name', 'status', 'updated_at'];
        if (! in_array($sort, $sortable, true)) {
            $sort = 'name';
        }

        $routerNodes = RouterNode::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('namespace_name', 'like', "%{$search}%")
                        ->orWhere('management_cidr', 'like', "%{$search}%")
                        ->orWhere('appliance_host', 'like', "%{$search}%");
                });
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderBy($sort, $direction)
            ->paginate(15)
            ->withQueryString();

        return view('routers.index', [
            'routerNodes' => $routerNodes,
            'filters' => [
                'q' => $search,
                'status' => $status,
                'sort' => $sort,
                'direction' => $direction,
            ],
            'statusOptions' => RouterNode::query()
                ->select('status')
                ->distinct()
                ->orderBy('status')
                ->pluck('status')
                ->all(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', RouterNode::class);

        return view('routers.create');
    }

    public function store(StoreRouterNodeRequest $request): RedirectResponse
    {
        $routerNode = app(RouterCapacity::class)->create([
            ...$request->safe()->only([
                'name',
                'namespace_name',
                'management_interface',
                'management_cidr',
                'appliance_host',
                'appliance_ssh_port',
                'appliance_ssh_user',
                'appliance_ssh_private_key',
                'appliance_ssh_known_host',
            ]),
            'status' => 'draft',
            'desired_configuration' => [],
        ], $request->user(), $this->configurationManager);

        return redirect()
            ->route('routers.show', $routerNode)
            ->with('status', 'Router namespace created and revision staged.');
    }

    public function show(RouterNode $router): View
    {
        $this->authorize('view', $router);

        $router->load([
            'appliedRevision',
            'desiredRevision',
            'latestObservedState',
        ]);

        return view('routers.show', [
            'routerNode' => $router,
            'routerState' => $this->stateProjector->project($router),
            'auditLogs' => $router->auditLogs()
                ->with('user')
                ->latest('created_at')
                ->paginate(10, pageName: 'audit_page')
                ->withQueryString(),
            'revisions' => $router->revisions()
                ->with('user')
                ->orderByDesc('revision_number')
                ->paginate(10, pageName: 'revision_page')
                ->withQueryString(),
        ]);
    }

    public function edit(RouterNode $router): View
    {
        $this->authorize('update', $router);

        return view('routers.edit', [
            'routerNode' => $router,
        ]);
    }

    public function update(UpdateRouterNodeRequest $request, RouterNode $router): RedirectResponse
    {
        $attributes = $request->safe()->only([
            'name',
            'namespace_name',
            'management_interface',
            'management_cidr',
            'appliance_host',
            'appliance_ssh_port',
            'appliance_ssh_user',
        ]);

        // Keep stored SSH material unless the operator explicitly replaces it.
        foreach (['appliance_ssh_private_key', 'appliance_ssh_known_host'] as $field) {
            if ($request->filled($field)) {
                $attributes[$field] = (string) $request->input($field);
            }
        }

        DB::transaction(function () use ($router, $attributes, $request): void {
            $locked = RouterNode::query()->lockForUpdate()->findOrFail($router->id);
            abort_if($locked->pending_operation_uuid || $locked->safe_mode_status === 'pending_confirm', 409, 'Finish the pending operation before changing appliance connection settings.');
            app(LicenseEnforcement::class)->assertMutationAllowed($locked);
            $locked->update($attributes);
            $this->configurationManager->createRevision($locked, $request->user());
        });

        return redirect()
            ->route('routers.show', $router)
            ->with('status', 'Router namespace updated and revision staged.');
    }
}
