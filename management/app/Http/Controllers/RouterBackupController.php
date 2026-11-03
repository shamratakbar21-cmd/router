<?php

namespace App\Http\Controllers;

use App\Models\RouterNode;
use App\Services\ConfigurationBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Throwable;

class RouterBackupController extends Controller
{
    public function __construct(private readonly ConfigurationBackupService $backups) {}

    public function show(RouterNode $router): View
    {
        $this->authorize('update', $router);

        return view('routers.backup', [
            'routerNode' => $router,
            'preview' => session('backup_preview'),
            'previewErrors' => session('backup_preview_errors', []),
        ]);
    }

    public function export(Request $request, RouterNode $router): Response
    {
        $this->authorize('update', $router);

        $includeSecrets = $request->boolean('include_secrets');
        $exported = $this->backups->export($router, $request->user(), $includeSecrets);

        $filename = sprintf(
            'nurrouter-backup-%s-%s.json',
            $router->namespace_name,
            now()->format('YmdHis'),
        );

        return response($exported['json'], 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function preview(Request $request, RouterNode $router): RedirectResponse
    {
        $this->authorize('update', $router);

        $validated = $request->validate([
            'backup_json' => ['required', 'string'],
        ]);

        $result = $this->backups->preview($validated['backup_json'], $router, $request->boolean('allow_legacy'));

        return redirect()
            ->route('routers.backup.show', $router)
            ->with('backup_preview', $result['preview'])
            ->with('backup_preview_errors', $result['errors'])
            ->with('backup_json_pending', $validated['backup_json'])
            ->with('backup_allow_legacy', $request->boolean('allow_legacy'))
            ->with('status', $result['ok'] ? 'Backup preview ready.' : 'Backup preview found issues.');
    }

    public function restore(Request $request, RouterNode $router): RedirectResponse
    {
        $this->authorize('update', $router);

        $validated = $request->validate([
            'backup_json' => ['required', 'string'],
            'confirm' => ['accepted'],
        ]);

        try {
            $result = $this->backups->restore(
                $router,
                $validated['backup_json'],
                $request->user(),
                confirm: true,
                allowLegacy: $request->boolean('allow_legacy'),
            );
        } catch (Throwable $throwable) {
            return redirect()
                ->route('routers.backup.show', $router)
                ->with('error', $throwable->getMessage());
        }

        return redirect()
            ->route('routers.desired-state.edit', $router)
            ->with('status', 'Backup restored into desired state as revision '.$result['revision_id']
                .'. Review and apply. Previous desired state is retained in revision history.');
    }
}
