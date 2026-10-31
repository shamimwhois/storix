<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
