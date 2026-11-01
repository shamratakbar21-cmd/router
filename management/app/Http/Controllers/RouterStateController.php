<?php

namespace App\Http\Controllers;

use App\Exceptions\ApplianceTransportException;
use App\Models\RouterNode;
use App\Services\RouterConfigurationManager;
use App\Services\RouterStateProjector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class RouterStateController extends Controller
{
    public function __construct(
        private readonly RouterConfigurationManager $configurationManager,
        private readonly RouterStateProjector $stateProjector,
    ) {}

    public function __invoke(Request $request, RouterNode $router): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $router);

        if ($request->boolean('refresh')) {
            try {
                $this->configurationManager->refreshObservedState($router, $request->user());
                $router->refresh();
            } catch (Throwable $throwable) {
                $exception = ApplianceTransportException::fromThrowable($throwable, 'observe');

                if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                    return response()->json([
                        'ok' => false,
                        'error' => [
                            'kind' => $exception->kind,
                            'message' => $exception->userMessage(),
                        ],
                    ], 502);
                }

                return redirect()
                    ->route('routers.show', $router)
                    ->with('error', $exception->userMessage())
                    ->with('error_kind', $exception->kind)
                    ->with('connection_state', 'disconnected');
            }
        }

        $router->load([
            'appliedRevision',
            'desiredRevision',
            'latestObservedState',
            'revisions.user',
            'auditLogs.user',
        ]);

        if ($request->boolean('refresh') && ! $request->expectsJson() && ! $request->wantsJson() && ! $request->ajax()) {
            return redirect()
                ->route('routers.show', $router)
                ->with('status', 'Observed state refreshed from the appliance.');
        }

        return response()->json($this->stateProjector->project($router));
    }
}
