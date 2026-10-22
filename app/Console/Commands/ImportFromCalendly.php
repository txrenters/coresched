<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Calendly\CalendlyClient;
use App\Services\Calendly\CalendlyImporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

class ImportFromCalendly extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calendly:import
        {--team= : Slug of the organization to import into; defaults to the only one}
        {--only=* : Limit to some resources (members, event_types, schedules, bookings)}
        {--since= : Only import meetings starting on or after this date (e.g. 2025-01-01)}
        {--dry-run : Report what would happen without writing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import event types, schedules, members and meetings from a Calendly account';

    /**
     * Execute the console command.
     */
    public function handle(CalendlyClient $client, CalendlyImporter $importer): int
    {
        if (! $client->isConfigured()) {
            $this->components->error('No Calendly API token configured. Set CALENDLY_API_KEY in your .env first.');

            return self::FAILURE;
        }

        $team = $this->resolveTeam();

        if ($team === null) {
            return self::FAILURE;
        }

        $only = $this->resolveResources();

        if ($only === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->components->info(sprintf(
            '%s into "%s": %s',
            $dryRun ? 'Dry run' : 'Importing',
            $team->name,
            implode(', ', $only),
        ));

        try {
            $result = $importer->import($team, $only, $dryRun, $this->resolveSince());
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->report($result, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Turn --since into the ISO timestamp Calendly expects.
     */
    protected function resolveSince(): ?string
    {
        $since = $this->option('since');

        return is_string($since) && filled($since)
            ? CarbonImmutable::parse($since)->toIso8601ZuluString()
            : null;
    }

    /**
     * Work out which organization to import into.
     */
    protected function resolveTeam(): ?Team
    {
        $slug = $this->option('team');

        if (is_string($slug)) {
            $team = Team::query()->where('slug', $slug)->first();

            if ($team === null) {
                $this->components->error("No organization found with the slug \"{$slug}\".");
            }

            return $team;
        }

        $teams = Team::query()->orderBy('name')->get();

        if ($teams->count() === 1) {
            return $teams->first();
        }

        if ($teams->isEmpty()) {
            $this->components->error('There are no organizations to import into.');

            return null;
        }

        $chosen = $this->choice('Which organization should this import into?', $teams->pluck('name', 'slug')->all());

        return $teams->firstWhere('name', $chosen) ?? Team::query()->where('slug', $chosen)->first();
    }

    /**
     * Validate the --only list.
     *
     * @return array<int, string>|null
     */
    protected function resolveResources(): ?array
    {
        $only = (array) $this->option('only');

        if ($only === []) {
            return CalendlyImporter::RESOURCES;
        }

        $unknown = array_diff($only, CalendlyImporter::RESOURCES);

        if ($unknown !== []) {
            $this->components->error(sprintf(
                'Unknown resource(s): %s. Choose from: %s.',
                implode(', ', $unknown),
                implode(', ', CalendlyImporter::RESOURCES),
            ));

            return null;
        }

        // Keep dependency order regardless of the order they were passed in.
        return array_values(array_filter(
            CalendlyImporter::RESOURCES,
            fn (string $resource) => in_array($resource, $only, true),
        ));
    }

    /**
     * Print what happened.
     *
     * @param  array{account: array<string, mixed>, tally: array<string, array<string, int>>, warnings: array<int, string>}  $result
     */
    protected function report(array $result, bool $dryRun): void
    {
        $account = $result['account'];

        if (filled($account['name'] ?? null)) {
            $this->components->twoColumnDetail('Calendly account', (string) $account['name']);
        }

        $rows = [];

        foreach ($result['tally'] as $resource => $counts) {
            $rows[] = [
                $resource,
                $counts['imported'],
                $counts['updated'],
                $counts['skipped'],
            ];
        }

        if ($rows === []) {
            $this->components->warn('Nothing was returned by Calendly.');

            return;
        }

        $this->newLine();
        $this->table(['Resource', 'New', 'Updated', 'Skipped'], $rows);

        foreach ($result['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        if ($dryRun) {
            $this->components->info('Dry run only — nothing was written. Re-run without --dry-run to apply.');
        }
    }
}
