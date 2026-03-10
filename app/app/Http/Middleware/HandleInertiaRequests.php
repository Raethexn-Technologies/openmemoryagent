<?php

namespace App\Http\Middleware;

use App\Services\IcpMemoryService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $icp = app(IcpMemoryService::class);

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error'   => fn () => $request->session()->get('error'),
            ],
            // Shared globally so AppLayout and any page can react to mode
            'icp' => [
                'mode'        => $icp->mode(),
                'canister_id' => $icp->canisterId(),
            ],
        ]);
    }
}
