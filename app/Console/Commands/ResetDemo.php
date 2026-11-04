<?php

namespace App\Console\Commands;

use App\Actions\Teams\ResetDemoOrganization;
use Illuminate\Console\Command;

class ResetDemo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'demo:reset';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild the public demo organization with fresh sample data';

    /**
     * Execute the console command.
     */
    public function handle(ResetDemoOrganization $resetDemoOrganization): int
    {
        $team = $resetDemoOrganization->handle();

        $this->components->info("Demo organization \"{$team->name}\" rebuilt.");

        return self::SUCCESS;
    }
}
