<?php

namespace Tests;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function createShop(User $vendor, array $attributes = []): Shop
    {
        return Shop::forceCreate(array_replace([
            'vendor_id' => $vendor->id,
            'name' => 'Eko Sunrise',
            'address' => '12 Market Road',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'country' => 'Nigeria',
        ], $attributes));
    }
}
