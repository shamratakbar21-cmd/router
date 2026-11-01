<?php

namespace App\Http\Controllers;

use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\LicenseActivationKey;
use App\Models\LicensePlan;
use App\Services\Licensing\LicenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LicenseAdminController extends Controller
{
    public function __construct(private readonly LicenseService $licenseService) {}

    public function index(): View
    {
        $this->authorize('viewAny', License::class);
        $this->licenseService->ensureDefaultPlans();

        return view('licensing.admin', [
            'plans' => LicensePlan::query()->orderBy('id')->get(),
            'keys' => LicenseActivationKey::query()->latest('id')->limit(50)->get(),
            'licenses' => License::query()->with('plan')->latest('id')->limit(50)->get(),
            'activations' => LicenseActivation::query()->with('license.plan')->latest('id')->limit(50)->get(),
        ]);
    }

    public function issueKey(Request $request): RedirectResponse
    {
        $this->authorize('manageKeys', License::class);

        $validated = $request->validate([
            'plan_code' => ['required', 'string', 'exists:license_plans,code'],
            'customer_label' => ['nullable', 'string', 'max:255'],
            'max_activations' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $plan = LicensePlan::query()->where('code', $validated['plan_code'])->firstOrFail();
        $issued = $this->licenseService->issueActivationKey(
            $plan,
            $request->user(),
            $validated['customer_label'] ?? null,
            (int) ($validated['max_activations'] ?? 1),
        );

        // Show plaintext once; never store or re-display it.
        return redirect()
            ->route('licensing.admin')
            ->with('status', 'Activation key issued. Copy it now; it will not be shown again.')
            ->with('issued_activation_key', $issued['plaintext'])
            ->with('issued_key_prefix', $issued['key']->key_prefix);
    }

    public function revokeKey(Request $request, LicenseActivationKey $key): RedirectResponse
    {
        $this->authorize('revoke', $key);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->licenseService->revokeKey($key, $validated['reason'], $request->user());

        return redirect()->route('licensing.admin')->with('status', 'Activation key revoked.');
    }

    public function revokeActivation(Request $request, LicenseActivation $activation): RedirectResponse
    {
        $this->authorize('revoke', License::class);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->licenseService->revokeActivation($activation, $validated['reason'], $request->user());

        return redirect()->route('licensing.admin')->with('status', 'Activation revoked.');
    }
}
