<?php

namespace App\Http\Controllers;

use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\RouterNode;
use App\Services\Licensing\LicensePolicyMatrix;
use App\Services\Licensing\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class LicenseActivationController extends Controller
{
    public function __construct(private readonly LicenseService $licenseService) {}

    public function show(RouterNode $router): View
    {
        $this->authorize('activate', License::class);

        $activation = $router->active_license_activation_id
            ? LicenseActivation::query()->with('license.plan')->find($router->active_license_activation_id)
            : null;

        $status = $activation
            ? $this->licenseService->evaluateStatus($activation)
            : LicensePolicyMatrix::statusPayload(LicensePolicyMatrix::STATE_UNLICENSED);

        return view('licensing.status', [
            'routerNode' => $router,
            'activation' => $activation,
            'status' => $status,
            'error' => session('license_error'),
            'refreshedEnvelope' => session('refreshed_license_envelope'),
        ]);
    }

    public function activate(Request $request, RouterNode $router): RedirectResponse
    {
        $this->authorize('activate', License::class);

        $validated = $request->validate([
            'activation_key' => ['required', 'string', 'max:256'],
            'device_id' => ['nullable', 'string', 'max:128'],
        ]);

        // Device id comes from router inventory / first-boot identity — optional override is operator-scoped,
        // never a client-supplied hash used as ownership proof.
        $deviceId = ($validated['device_id'] ?? null)
            ?: ($router->device_id ?: 'router-'.$router->id.'-'.substr(hash('sha256', $router->namespace_name), 0, 16));

        $allowRenew = $router->active_license_activation_id !== null
            || in_array($router->license_status, [
                LicensePolicyMatrix::STATE_VALID,
                LicensePolicyMatrix::STATE_OFFLINE_WITHIN_GRACE,
                LicensePolicyMatrix::STATE_EXPIRED_WITHIN_GRACE,
                LicensePolicyMatrix::STATE_EXPIRED_AFTER_GRACE,
                LicensePolicyMatrix::STATE_REVOKED,
                'active',
                'grace',
                'expired',
            ], true);

        try {
            $this->licenseService->activateOnline(
                $validated['activation_key'],
                $deviceId,
                $router,
                null,
                null,
                $request->user(),
                allowRenew: $allowRenew,
            );
        } catch (Throwable $throwable) {
            return redirect()
                ->route('licensing.status', $router)
                ->with('license_error', $throwable->getMessage())
                ->with('error', $throwable->getMessage());
        }

        return redirect()
            ->route('licensing.status', $router)
            ->with('status', 'License activated successfully.');
    }

    public function refresh(Request $request, RouterNode $router): RedirectResponse|JsonResponse
    {
        $this->authorize('activate', License::class);

        $activation = $router->active_license_activation_id
            ? LicenseActivation::query()->find($router->active_license_activation_id)
            : null;

        if ($activation === null) {
            $message = 'No activation to refresh.';
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $message], 422);
            }

            return redirect()->route('licensing.status', $router)->with('license_error', $message);
        }

        try {
            $result = $this->licenseService->refreshStatus($activation, $request->user());
        } catch (Throwable $throwable) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $throwable->getMessage()], 422);
            }

            return redirect()
                ->route('licensing.status', $router)
                ->with('license_error', $throwable->getMessage());
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'status' => $result['status'],
                'envelope' => $result['envelope'],
            ]);
        }

        return redirect()
            ->route('licensing.status', $router)
            ->with('status', 'Authenticated license status refreshed. Install the signed envelope on the appliance.')
            ->with('refreshed_license_envelope', json_encode($result['envelope'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function offlineRequest(Request $request, RouterNode $router): JsonResponse|View
    {
        $this->authorize('activate', License::class);

        $deviceId = $router->device_id ?: 'router-'.$router->id.'-'.substr(hash('sha256', $router->namespace_name), 0, 16);
        $payload = $this->licenseService->buildOfflineRequest($deviceId);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'request' => $payload]);
        }

        return view('licensing.offline', [
            'routerNode' => $router,
            'offlineRequest' => $payload,
        ]);
    }

    public function offlineActivate(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('activate', License::class);

        $validated = $request->validate([
            'activation_key' => ['required', 'string', 'max:256'],
            'offline_request_json' => ['required', 'string'],
        ]);

        try {
            /** @var array<string, mixed> $offlineRequest */
            $offlineRequest = json_decode($validated['offline_request_json'], true, flags: JSON_THROW_ON_ERROR);
            $result = $this->licenseService->activateOffline(
                $validated['activation_key'],
                $offlineRequest,
                $request->user(),
            );
        } catch (Throwable $throwable) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $throwable->getMessage()], 422);
            }

            return redirect()
                ->route('licensing.admin')
                ->with('error', $throwable->getMessage());
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'envelope' => $result['envelope'],
            ]);
        }

        return redirect()
            ->route('licensing.admin')
            ->with('status', 'Offline activation response ready.')
            ->with('offline_envelope', json_encode($result['envelope'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function transfer(Request $request): RedirectResponse
    {
        $this->authorize('transfer', License::class);

        $validated = $request->validate([
            'from_activation_id' => ['required', 'integer', 'exists:license_activations,id'],
            'to_device_id' => ['required', 'string', 'max:128'],
            'activation_key' => ['required', 'string', 'max:256'],
        ]);

        try {
            $from = LicenseActivation::query()->findOrFail($validated['from_activation_id']);
            $this->licenseService->transferDevice(
                $from,
                $validated['to_device_id'],
                $validated['activation_key'],
                $request->user(),
            );
        } catch (Throwable $throwable) {
            return redirect()->route('licensing.admin')->with('error', $throwable->getMessage());
        }

        return redirect()->route('licensing.admin')->with('status', 'Device transfer completed.');
    }

    public function verifyPayload(Request $request): JsonResponse
    {
        $this->authorize('activate', License::class);

        $validated = $request->validate([
            'envelope' => ['required', 'array'],
            'expected_device_id' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $payload = $this->licenseService->verifyEnvelope(
                $validated['envelope'],
                $validated['expected_device_id'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'payload' => $payload]);
    }
}
