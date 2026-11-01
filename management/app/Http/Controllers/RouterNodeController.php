<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRouterNodeRequest;
use App\Http\Requests\UpdateRouterNodeRequest;
use App\Models\RouterNode;
use App\Services\RouterConfigurationManager;
use App\Services\RouterStateProjector;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RouterNodeController extends Controller
{
    public function __construct(
        private readonly RouterConfigurationManager $configurationManager,
        private readonly RouterStateProjector $stateProjector,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', RouterNode::class);

        return view('routers.index', [
            'routerNodes' => RouterNode::query()
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', RouterNode::class);

        return view('routers.create');
    }

    public function store(StoreRouterNodeRequest $request): RedirectResponse
    {
        $routerNode = RouterNode::create([
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
        ]);

        $this->configurationManager->createRevision($routerNode, $request->user());

        return redirect()
            ->route('routers.show', $routerNode)
            ->with('status', 'Router namespace created and revision staged.');
    }

    public function show(RouterNode $router): View
    {
        $this->authorize('view', $router);

        return view('routers.show', [
            'routerNode' => $router->load([
                'appliedRevision',
                'revisions.user',
                'desiredRevision',
                'latestObservedState',
                'auditLogs.user',
            ]),
            'routerState' => $this->stateProjector->project($router),
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

        $router->update($attributes);
        $this->configurationManager->createRevision($router, $request->user());

        return redirect()
            ->route('routers.show', $router)
            ->with('status', 'Router namespace updated and revision staged.');
    }
}
