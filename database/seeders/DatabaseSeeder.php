<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Billing\Money;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** A local operator with credits to spend: test@example.com / password. */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $user->forceFill(['is_admin' => true])->save();
        $user->creditPurchases()->create(['amount_nanos' => 25 * Money::NANOS_PER_DOLLAR]);
    }
}
