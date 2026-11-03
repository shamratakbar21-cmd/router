<?php

namespace App\Services;

use App\Models\LicenseActivation;
use App\Models\RouterNode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouterCapacity
{
    /** This installation is one managed inventory; activation seats are a separate limit. */
    public function create(array $attributes, User $user, RouterConfigurationManager $manager): RouterNode
    {
        return DB::transaction(function () use ($attributes, $user, $manager): RouterNode {
            // A stable singleton row serializes inventory admission, including an empty inventory.
            User::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $limits = LicenseActivation::query()->with('license')->where('status', 'active')
                ->whereHas('routerNode', fn ($query) => $query->whereColumn('active_license_activation_id', 'license_activations.id'))
                ->get()->map(fn ($activation) => (int) ($activation->license?->entitlements['max_routers'] ?? 0))
                ->filter(fn ($limit) => $limit > 0);
            if ($limits->isNotEmpty() && RouterNode::count() >= $limits->min()) {
                throw ValidationException::withMessages(['name' => 'The active plan max_routers inventory limit has been reached. Activation-key seats do not increase this limit.']);
            }
            $router = RouterNode::create($attributes);
            $manager->createRevision($router, $user);

            return $router;
        });
    }
}
