<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storix_transfers', function (Blueprint $table): void {
            $table->timestamp('next_retry_at')->nullable()->after('resume_lease_expires_at');
            $table->timestamp('last_heartbeat_at')->nullable()->after('next_retry_at');
            $table->index(['state', 'next_retry_at'], 'storix_transfers_next_retry_index');
        });
    }

    public function down(): void
    {
        Schema::table('storix_transfers', function (Blueprint $table): void {
            $table->dropIndex('storix_transfers_next_retry_index');
            $table->dropColumn(['next_retry_at', 'last_heartbeat_at']);
        });
    }
};
