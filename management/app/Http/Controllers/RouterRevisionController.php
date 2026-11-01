<?php

namespace App\Http\Controllers;

use App\Exceptions\ApplianceTransportException;
use App\Http\Requests\ApplyRouterRevisionRequest;
use App\Models\ConfigurationRevision;
use App\Models\RouterNode;
use App\Services\RouterConfigurationManager;
use Illuminate\Http\RedirectResponse;
use Throwable;

class RouterRevisionController extends Controller
{
    public function __construct(private readonly RouterConfigurationManager $configurationManager) {}

    public function apply(ApplyRouterRevisionRequest $request, RouterNode $router): RedirectResponse
    {
        try {
            $this->configurationManager->applyCurrentRevision(
                $router,
                $request->user(),
                $request->integer('expected_lock_version'),
                $request->integer('safe_mode_minutes'),
            );
        } catch (Throwable $throwable) {
            $exception = ApplianceTransportException::fromThrowable($throwable, 'apply');

            return redirect()
                ->route('routers.show', $router)
                ->with('error', $exception->userMessage())
                ->with('error_kind', $exception->kind)
                ->with('staged_preserved', true);
        }

        $router->refresh();

        return redirect()
            ->route('routers.show', $router)
            ->with('status', "Revision {$router->desired_revision_id} applied successfully.");
    }

    public function confirm(ApplyRouterRevisionRequest $request, RouterNode $router): RedirectResponse
    {
        try {
            $this->configurationManager->confirmSafeMode($router, $request->user());
        } catch (Throwable $throwable) {
            $exception = ApplianceTransportException::fromThrowable($throwable, 'confirm');

            return redirect()
                ->route('routers.show', $router)
                ->with('error', $exception->userMessage())
                ->with('error_kind', $exception->kind);
        }

        return redirect()
            ->route('routers.show', $router)
            ->with('status', "Safe Mode was confirmed for {$router->name}.");
    }

    public function rollback(
        ApplyRouterRevisionRequest $request,
        RouterNode $router,
        ConfigurationRevision $revision,
    ): RedirectResponse {
        $this->authorize('rollback', $router);

        try {
            $this->configurationManager->rollbackToRevision($router, $revision, $request->user());
        } catch (Throwable $throwable) {
            $exception = ApplianceTransportException::fromThrowable($throwable, 'apply');

            return redirect()
                ->route('routers.show', $router)
                ->with('error', $exception->userMessage())
                ->with('error_kind', $exception->kind)
                ->with('staged_preserved', true);
        }

        return redirect()
            ->route('routers.show', $router)
            ->with('status', "Rollback to revision {$revision->revision_number} was applied.");
    }
}
