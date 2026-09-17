<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PackageRequest;
use App\Models\ActivityLog;
use App\Models\InternetPackage;
use App\Models\Router;
use App\Models\RouterPackageProfile;
use App\Services\MikrotikService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PackageController extends Controller
{
    public function index(Request $request)
    {
        $query = InternetPackage::with('routerProfiles.router:id,name');

        $routerIds = $request->attributes->get('admin_router_ids');
        if ($routerIds !== null) {
            $query->whereHas('routerProfiles', fn ($profiles) => $profiles->whereIn('router_id', $routerIds));
            $query->with(['routerProfiles' => fn ($profiles) => $profiles->whereIn('router_id', $routerIds)->with('router:id,name')]);
        }

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(
            $query->latest()->paginate($request->integer('per_page', 10))
        );
    }

    public function store(PackageRequest $request)
    {
        $data = $request->validated();
        $profiles = $data['profiles'] ?? [];
        $this->ensureProfileAccess($request, $profiles);
        unset($data['profiles']);
        $data['status'] = $data['status'] ?? 'active';
        $data['sales_channel'] = $data['sales_channel'] ?? 'subscription';

        $package = DB::transaction(function () use ($data, $profiles) {
            $package = InternetPackage::create($data);
            $this->syncProfiles($package, $profiles);

            return $package;
        });

        $warnings = $this->pushProfilesToRouters($package, $profiles);

        ActivityLog::record('package.created', "Package '{$package->name}' created.", ['user_id' => $request->user()->id]);

        return response()->json(
            array_merge(
                $package->load('routerProfiles.router:id,name')->toArray(),
                ['router_sync_warnings' => $warnings]
            ),
            201
        );
    }

    public function show(Request $request, InternetPackage $package)
    {
        $this->ensurePackageAccess($request, $package);

        $ids = $request->attributes->get('admin_router_ids');

        return response()->json($package->load(['routerProfiles' => fn ($query) => $query
            ->when($ids !== null, fn ($q) => $q->whereIn('router_id', $ids))->with('router:id,name')]));
    }

    public function update(PackageRequest $request, InternetPackage $package)
    {
        $data = $request->validated();
        $profiles = $data['profiles'] ?? null; // null = "not submitted", leave mappings untouched
        $this->ensurePackageAccess($request, $package);
        $this->ensurePackageWriteAccess($request, $package);
        if ($profiles !== null) {
            $this->ensureProfileAccess($request, $profiles);
        }
        unset($data['profiles']);

        DB::transaction(function () use ($package, $data, $profiles) {
            $package->update($data);

            if ($profiles !== null) {
                $this->syncProfiles($package, $profiles);
            }
        });

        $warnings = $profiles !== null ? $this->pushProfilesToRouters($package, $profiles) : [];

        ActivityLog::record('package.updated', "Package '{$package->name}' updated.", ['user_id' => $request->user()->id]);

        return response()->json(array_merge(
            $package->fresh()->load('routerProfiles.router:id,name')->toArray(),
            ['router_sync_warnings' => $warnings]
        ));
    }

    public function destroy(Request $request, InternetPackage $package)
    {
        $this->ensurePackageAccess($request, $package);
        $this->ensurePackageWriteAccess($request, $package);
        // Purchase::active() covers every active-equivalent status
        // (active, voucher_assigned, completed) — a single 'active' ===
        // check here would have missed voucher-fulfilled purchases
        // entirely and let this package be deleted out from under them.
        $usage = [
            'purchase' => $package->purchases()->count(),
            'voucher' => $package->vouchers()->count(),
            'subscription' => DB::table('subscriptions')->where('package_id', $package->id)->count(),
            'order' => DB::table('orders')->where('package_id', $package->id)->count(),
            'free campaign' => DB::table('free_trial_campaigns')->where('package_id', $package->id)->count(),
        ];
        $usedBy = collect($usage)
            ->filter()
            ->map(fn ($count, $type) => "{$count} {$type}".($count === 1 ? '' : 's'))
            ->values();

        if ($usedBy->isNotEmpty()) {
            return response()->json([
                'message' => 'This package cannot be deleted because it is used by '.$usedBy->join(', ').'. Set its status to Inactive instead to preserve billing history.',
            ], 422);
        }

        try {
            $package->delete();
        } catch (QueryException) {
            return response()->json([
                'message' => 'This package is linked to existing records and cannot be deleted. Set its status to Inactive instead.',
            ], 422);
        }

        ActivityLog::record('package.deleted', "Package '{$package->name}' deleted.", ['user_id' => $request->user()->id]);

        return response()->json(['message' => 'Package deleted.']);
    }

    protected function ensurePackageAccess(Request $request, InternetPackage $package): void
    {
        $routerIds = $request->attributes->get('admin_router_ids');
        if ($routerIds !== null && ! $package->routerProfiles()->whereIn('router_id', $routerIds)->exists()) {
            abort(403, 'You do not have access to this package.');
        }
    }

    protected function ensureProfileAccess(Request $request, array $profiles): void
    {
        foreach ($profiles as $profile) {
            abort_unless(
                $request->user()->canAccessRouter((int) $profile['router_id']),
                403,
                'You cannot configure a package for an unassigned router.'
            );
        }
    }

    protected function ensurePackageWriteAccess(Request $request, InternetPackage $package): void
    {
        if (! $request->user() || $request->user()->isSuperAdmin()) {
            return;
        }
        $ids = $request->user()->routers()->pluck('routers.id');
        abort_if($package->routerProfiles()->whereNotIn('router_id', $ids)->exists(), 403,
            'This shared package also affects routers outside your access. Contact a super administrator.');
    }

    /**
     * Replace this package's router_package_profiles rows with the given
     * set — removes mappings for routers no longer included, upserts the
     * rest. Full-replace (not merge) so removing a router from the form
     * actually removes its mapping.
     */
    protected function syncProfiles(InternetPackage $package, array $profiles): void
    {
        $keepRouterIds = collect($profiles)->pluck('router_id');

        RouterPackageProfile::where('package_id', $package->id)
            ->whereNotIn('router_id', $keepRouterIds)
            ->delete();

        foreach ($profiles as $profile) {
            RouterPackageProfile::updateOrCreate(
                ['package_id' => $package->id, 'router_id' => $profile['router_id']],
                [
                    'profile_name' => $profile['profile_name'],
                    'shared_users' => $profile['shared_users'] ?? 1,
                ]
            );
        }
    }

    /**
     * Actually create/update the RouterOS hotspot user profile on every
     * mapped router, live — the DB mapping alone used to leave the router
     * untouched, so a package could be fully configured in the admin
     * panel while every user created against it silently got RouterOS's
     * default (unlimited) speed. Run after the DB transaction commits so
     * a slow/offline router can't hold a transaction open; a router that
     * can't be reached produces a warning, not a failed save — the admin
     * can retry once the router is back.
     */
    protected function pushProfilesToRouters(InternetPackage $package, array $profiles): array
    {
        $warnings = [];

        foreach ($profiles as $profile) {
            $router = Router::find($profile['router_id']);

            if (! $router || $router->isManual()) {
                continue;
            }

            $result = (new MikrotikService($router))
                ->ensureHotspotUserProfile(
                    $profile['profile_name'],
                    $package->speed_limit,
                    $router->address_pool,
                    (int) ($profile['shared_users'] ?? 1),
                );

            if (! $result['success']) {
                $warnings[] = "Couldn't sync profile '{$profile['profile_name']}' to router '{$router->name}': {$result['error']}";
            }
        }

        return $warnings;
    }
}
