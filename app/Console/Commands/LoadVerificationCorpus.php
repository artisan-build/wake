<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Verification\ScaleCorpusLoader;
use App\Verification\ScaleCorpusPlan;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class LoadVerificationCorpus extends Command
{
    public const string CONFIRMATION = 'LOAD-WAKE-SYNTHETIC-CORPUS';

    protected $signature = 'wake:verification-load
        {--run=pr7-cloud : Stable verification run key}
        {--rows=50000000 : Total synthetic events}
        {--apps=40 : Synthetic applications}
        {--days=30 : Whole UTC days}
        {--chunk=100000 : Rows per atomic DuckLake insert}
        {--dry-run : Print the validated plan without writing}
        {--confirm= : Required destructive confirmation value}';

    protected $description = 'Create or resume the destructive synthetic Wake scale-verification corpus.';

    public function handle(): int
    {
        try {
            $plan = ScaleCorpusPlan::fromOptions([
                'run' => $this->option('run'), 'rows' => $this->option('rows'), 'apps' => $this->option('apps'),
                'days' => $this->option('days'), 'chunk' => $this->option('chunk'),
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        }

        $this->line("run_key={$plan->runKey} rows={$plan->rows} apps={$plan->apps} days={$plan->days} chunk={$plan->chunk} chunks={$plan->chunks()}");
        if ((bool) $this->option('dry-run')) {
            return self::SUCCESS;
        }
        if ($this->option('confirm') !== self::CONFIRMATION) {
            $this->components->error('Refusing writes: pass --confirm='.self::CONFIRMATION.'.');

            return self::FAILURE;
        }

        $started = microtime(true);
        try {
            $counts = app(ScaleCorpusLoader::class)->run($plan, function (int $completed, int $total, int $chunk) use ($started, $plan): void {
                $this->line(sprintf('run_key=%s completed=%d total=%d chunk=%d elapsed_seconds=%.3f', $plan->runKey, $completed, $total, $chunk, microtime(true) - $started));
            });
        } catch (Throwable) {
            $this->line(sprintf('run_key=%s failed=1 elapsed_seconds=%.3f', $plan->runKey, microtime(true) - $started));

            return self::FAILURE;
        }
        $this->line(sprintf(
            'run_key=%s lake_rows=%d locator_rows=%d error_rows=%d hourly_events=%d elapsed_seconds=%.3f',
            $plan->runKey, $counts['lake_rows'], $counts['locators'], $counts['errors'], $counts['hourly_events'], microtime(true) - $started,
        ));

        return self::SUCCESS;
    }
}
