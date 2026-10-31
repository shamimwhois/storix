<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Console\Commands;

use Illuminate\Console\Command;
use ShamimStack\Storix\Services\TransferRecoveryService;

final class RecoverStaleTransfersCommand extends Command
{
    protected $signature = 'storix:transfers:recover
        {--limit=100 : Maximum number of stale transfers to inspect}
        {--stale-seconds= : Override the minimum stale age in seconds}
        {--dry-run : Inspect eligible transfers without changing storage or database state}';

    protected $description = 'Reconcile stale Storix transfers and clean up expired sessions.';

    public function handle(TransferRecoveryService $recovery): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->components->error('The --limit option must be an integer between 1 and 1000.');

            return self::INVALID;
        }

        $configuredStaleSeconds = (int) config('storix.transfers.recovery_stale_seconds', 300);
        $staleSeconds = $this->option('stale-seconds') === null
            ? $configuredStaleSeconds
            : filter_var($this->option('stale-seconds'), FILTER_VALIDATE_INT);

        if ($staleSeconds === false || $staleSeconds < 30 || $staleSeconds > 2_592_000) {
            $this->components->error('The stale age must be between 30 seconds and 30 days.');

            return self::INVALID;
        }

        $summary = $recovery->recover(
            limit: $limit,
            staleSeconds: $staleSeconds,
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->table(
            ['Examined', 'Recovered', 'Expired', 'Waiting', 'Skipped', 'Failed'],
            [[
                $summary['examined'],
                $summary['recovered'],
                $summary['expired'],
                $summary['waiting'],
                $summary['skipped'],
                $summary['failed'],
            ]],
        );

        if ($summary['failed'] > 0) {
            $this->components->warn('Some transfers could not be reconciled. Inspect sanitized application logs.');

            return self::FAILURE;
        }

        $this->components->info($this->option('dry-run')
            ? 'Dry run completed. No transfer or storage state was changed.'
            : 'Transfer recovery completed.');

        return self::SUCCESS;
    }
}
