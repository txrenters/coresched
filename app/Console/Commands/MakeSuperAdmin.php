<?php

namespace App\Console\Commands;

use App\Actions\Scheduling\ApplyDefaultHolidays;
use App\Actions\Scheduling\CreateDefaultAvailability;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MakeSuperAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:super-admin
        {email : The account to promote, created if it does not exist}
        {--name= : Name to use when creating a new account}
        {--password= : Password to set; a strong one is generated when omitted}
        {--revoke : Take super admin away from this account instead}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Grant or revoke super admin, which can see and act across every organization';

    public function __construct(
        protected CreateDefaultAvailability $createDefaultAvailability,
        protected ApplyDefaultHolidays $applyDefaultHolidays,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        $email = is_string($email) ? strtolower(trim($email)) : '';
        $password = $this->option('password');
        $password = is_string($password) && filled($password) ? $password : null;
        $name = $this->option('name');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error("\"{$email}\" is not a valid email address.");

            return self::FAILURE;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($this->option('revoke')) {
            return $this->revoke($user, $email);
        }

        $generated = null;

        if ($user === null) {
            $generated = $password ?? Str::password(16, symbols: false);

            $user = User::create([
                'name' => is_string($name) && filled($name) ? $name : Str::of($email)->before('@')->headline()->toString(),
                'email' => $email,
                'password' => Hash::make($generated),
                'email_verified_at' => now(),
            ]);
        } elseif ($password !== null) {
            $generated = $password;
            $user->forceFill(['password' => Hash::make($password)])->save();
        }

        $user->forceFill([
            'is_super_admin' => true,
            // Sign-in is pointless while the address is unverified.
            'email_verified_at' => $user->email_verified_at ?? now(),
            /*
             * A super admin joins no organization, so they would otherwise have
             * no current one and nowhere to land after signing in. Start them in
             * a shared organization if there is one.
             */
            'current_team_id' => $user->current_team_id ?? Team::query()
                ->orderBy('name')
                ->value('id'),
        ])->save();

        /*
         * CreateNewUser and CreateTeamUser both bootstrap availability, and
         * this command is the only account-creating path that did not. Without
         * a schedule the Availability screen has nothing to select and no
         * obvious way to make one, so the account looks broken on first use.
         */
        if ($user->availabilitySchedules()->doesntExist()) {
            $this->createDefaultAvailability->handle($user);
            $this->applyDefaultHolidays->handle($user);
        }

        $this->components->info("{$user->email} is now a super admin.");

        if ($generated !== null) {
            $this->components->twoColumnDetail('Password', $generated);
            $this->components->warn('Shown once. Store it somewhere safe and change it after signing in.');
        }

        return self::SUCCESS;
    }

    /**
     * Take the role away again.
     */
    protected function revoke(?User $user, string $email): int
    {
        if ($user === null) {
            $this->components->error("No account found for {$email}.");

            return self::FAILURE;
        }

        $user->forceFill(['is_super_admin' => false])->save();

        $this->components->info("{$user->email} is no longer a super admin.");

        return self::SUCCESS;
    }
}
