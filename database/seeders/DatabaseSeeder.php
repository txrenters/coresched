<?php

namespace Database\Seeders;

use App\Actions\Scheduling\CreateDefaultAvailability;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * The default accounts for first logins; everyone starts with "password".
     *
     * Registration is invitation-only, so the first accounts have to come
     * from here; further teammates are invited from inside the app. Built
     * without factories on purpose: Faker is a dev dependency, and this
     * seeder also runs on production via the seed_default_users migration.
     *
     * @var array<int, array{name: string, email: string, is_super_admin?: bool}>
     */
    protected array $defaultUsers = [
        ['name' => 'Super Admin', 'email' => 'superadmin@texasrenters.com', 'is_super_admin' => true],
    ];

    /**
     * Seed the application's database.
     *
     * Safe to rerun: accounts that already exist are left untouched.
     */
    public function run(): void
    {
        foreach ($this->defaultUsers as $defaults) {
            if (User::query()->where('email', $defaults['email'])->exists()) {
                continue;
            }

            DB::transaction(function () use ($defaults) {
                $user = User::create([...$defaults, 'password' => 'password']);

                // Seeded accounts skip the verification email round trip.
                $user->forceFill(['email_verified_at' => now()])->save();

                // No organization: a super admin belongs to none and creates
                // the first one from the organizations screen.
                app(CreateDefaultAvailability::class)->handle($user);
            });
        }
    }
}
