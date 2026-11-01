<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRouterDesiredStateRequest;
use App\Models\RouterNode;
use App\Services\RouterConfigurationManager;
use App\Services\RouterStateProjector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

class RouterDesiredStateController extends Controller
{
    public function __construct(
        private readonly RouterConfigurationManager $configurationManager,
        private readonly RouterStateProjector $stateProjector,
    ) {}

    public function edit(RouterNode $router): View
    {
        $this->authorize('update', $router);

        return view('routers.configure', [
            'routerNode' => $router->load([
                'appliedRevision',
                'desiredRevision',
                'latestObservedState',
                'revisions.user',
                'auditLogs.user',
            ]),
            'routerState' => $this->stateProjector->project($router),
        ]);
    }

    public function show(RouterNode $router): JsonResponse
    {
        $this->authorize('view', $router);

        $router->load([
            'appliedRevision',
            'desiredRevision',
            'latestObservedState',
            'revisions.user',
            'auditLogs.user',
        ]);

        return response()->json([
            'desired' => $this->stateProjector->project($router)['desired'],
        ]);
    }

    public function update(UpdateRouterDesiredStateRequest $request, RouterNode $router): JsonResponse|RedirectResponse
    {
        try {
            $revision = $this->configurationManager->updateDesiredState(
                $router,
                $request->publicConfiguration(),
                $request->secretConfiguration(),
                $request->user(),
            );
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'error' => [
                        'kind' => 'entitlement_or_license_blocked',
                        'message' => $message,
                    ],
                ], 422);
            }

            return redirect()
                ->route('routers.desired-state.edit', $router)
                ->withInput()
                ->with('error', $message)
                ->with('error_kind', 'entitlement_or_license_blocked')
                ->with('staged_preserved', true);
        }

        $router->refresh()->load([
            'appliedRevision',
            'desiredRevision',
            'latestObservedState',
            'revisions.user',
            'auditLogs.user',
        ]);

        if (! $request->expectsJson()) {
            return redirect()
                ->route('routers.desired-state.edit', $router)
                ->with('status', "Desired routing state staged as revision {$revision->revision_number}.");
        }

        return response()->json([
            'message' => 'Desired routing state staged.',
            'revision_id' => $revision->id,
            'revision_number' => $revision->revision_number,
            'state' => $this->stateProjector->project($router),
        ]);
    }
}
