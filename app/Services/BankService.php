<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Nigerian bank list + account-name lookup (Paystack "resolve account" is free and server-side only).
// Without PAYSTACK_SECRET_KEY it falls back to a built-in list and the lookup is skipped (manual review).
class BankService
{
    public function configured(): bool
    {
        return (bool) config('services.paystack.secret');
    }

    public function banks(): array
    {
        $hours = (int) config('marketplace.banks_cache_hours', 24);

        return Cache::remember('banks:ng', now()->addHours($hours), function () {
            if ($this->configured()) {
                try {
                    $response = Http::withToken(config('services.paystack.secret'))
                        ->timeout(10)
                        ->get('https://api.paystack.co/bank', [
                            'country' => 'nigeria',
                            'currency' => 'NGN',
                            'perPage' => 200,
                        ]);

                    if ($response->successful()) {
                        $banks = collect($response->json('data', []))
                            ->filter(fn ($b) => !empty($b['code']) && !empty($b['name']))
                            ->map(fn ($b) => ['code' => (string) $b['code'], 'name' => $b['name']])
                            ->unique('code')
                            ->sortBy('name')
                            ->values()
                            ->all();

                        if (count($banks) >= 10) {
                            return $banks;
                        }
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return $this->fallback();
        });
    }

    // ['status' => verified|invalid|unavailable, 'name' => account holder name or null]
    public function resolve(string $accountNumber, string $bankCode): array
    {
        if (!$this->configured()) {
            return ['status' => 'unavailable', 'name' => null];
        }

        try {
            $response = Http::withToken(config('services.paystack.secret'))
                ->timeout(10)
                ->get('https://api.paystack.co/bank/resolve', [
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                ]);

            if ($response->successful() && $response->json('status') === true) {
                return ['status' => 'verified', 'name' => $response->json('data.account_name')];
            }

            if (in_array($response->status(), [400, 404, 422], true)) {
                return ['status' => 'invalid', 'name' => null];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return ['status' => 'unavailable', 'name' => null];
    }

    // Used only when no Paystack key is set (local development)
    private function fallback(): array
    {
        return [
            ['code' => '044', 'name' => 'Access Bank'],
            ['code' => '050', 'name' => 'Ecobank Nigeria'],
            ['code' => '070', 'name' => 'Fidelity Bank'],
            ['code' => '011', 'name' => 'First Bank of Nigeria'],
            ['code' => '214', 'name' => 'First City Monument Bank'],
            ['code' => '058', 'name' => 'Guaranty Trust Bank'],
            ['code' => '50211', 'name' => 'Kuda Bank'],
            ['code' => '50515', 'name' => 'Moniepoint MFB'],
            ['code' => '999992', 'name' => 'OPay'],
            ['code' => '999991', 'name' => 'PalmPay'],
            ['code' => '076', 'name' => 'Polaris Bank'],
            ['code' => '221', 'name' => 'Stanbic IBTC Bank'],
            ['code' => '232', 'name' => 'Sterling Bank'],
            ['code' => '032', 'name' => 'Union Bank of Nigeria'],
            ['code' => '033', 'name' => 'United Bank for Africa'],
            ['code' => '035', 'name' => 'Wema Bank'],
            ['code' => '057', 'name' => 'Zenith Bank'],
        ];
    }
}
