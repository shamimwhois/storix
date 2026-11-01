<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ShamimStack\Storix\Enums\TransferDirection;
use ShamimStack\Storix\Enums\TransferState;
use ShamimStack\Storix\Models\Transfer;
use ShamimStack\Storix\Tests\TestCase;

final class RecoveryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_command_is_registered_and_dry_run_is_safe(): void
    {
        $this->artisan('storix:transfers:recover', [
            '--dry-run' => true,
            '--limit' => 10,
        ])->assertExitCode(0);
    }

    public function test_successfully_terminated_transfer_does_not_fail_the_recovery_command(): void
    {
        $transfer = Transfer::query()->create([
            'direction' => TransferDirection::UPLOAD,
            'driver' => 'local',
            'state' => TransferState::RETRY_WAIT,
            'source_name' => 'permanent-failure.txt',
            'total_bytes' => 12,
            'transferred_bytes' => 0,
            'total_chunks' => 0,
            'completed_chunks' => 0,
            'fingerprint' => hash('sha256', 'permanent-failure'),
            'idempotency_key' => 'permanent-failure-command',
            'attempts' => 1,
            'max_attempts' => 5,
            'last_error_code' => 'UPLOAD_INTEGRITY_FAILED',
            'expires_at' => now()->addDay(),
        ]);

        DB::table('storix_transfers')->where('id', $transfer->id)->update([
            'updated_at' => now()->subMinutes(10),
        ]);

        $this->artisan('storix:transfers:recover', [
            '--limit' => 10,
            '--stale-seconds' => 30,
        ])->assertExitCode(0);

        $transfer->refresh();

        self::assertSame(TransferState::FAILED, $transfer->state);
    }

    public function test_recovery_command_rejects_invalid_limits(): void
    {
        $this->artisan('storix:transfers:recover', [
            '--dry-run' => true,
            '--limit' => 0,
        ])->assertExitCode(2);
    }

    public function test_recovery_command_rejects_an_unreasonably_small_stale_age(): void
    {
        $this->artisan('storix:transfers:recover', [
            '--dry-run' => true,
            '--stale-seconds' => 1,
        ])->assertExitCode(2);
    }
}
