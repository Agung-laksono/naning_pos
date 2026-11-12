<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'store' => function () {
                $defaultName = config('app.name', 'Rozak Mart');

                try {
                    $settings = Setting::whereIn('key', [
                        'store_name',
                        'store_address',
                        'store_phone',
                        'store_description',
                        'receipt_footer',
                    ])->pluck('value', 'key');
                } catch (\Throwable $e) {
                    $settings = collect();
                }

                return [
                    'name' => trim((string) ($settings['store_name'] ?? '')) ?: $defaultName,
                    'address' => trim((string) ($settings['store_address'] ?? '')),
                    'phone' => trim((string) ($settings['store_phone'] ?? '')),
                    'description' => trim((string) ($settings['store_description'] ?? '')),
                    'footer' => trim((string) ($settings['receipt_footer'] ?? '')),
                ];
            },
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'customer' => fn () => $request->session()->get('customer'),
                'product' => fn () => $request->session()->get('product'),
                'last_transaction' => fn () => $request->session()->get('last_transaction'),
                'active_shift' => fn () => $request->session()->get('active_shift'),
            ],
        ];
    }
}
