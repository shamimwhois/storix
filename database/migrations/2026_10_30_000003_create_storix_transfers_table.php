<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storix_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('file_id')->nullable()->constrained('storix_files')->restrictOnDelete();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('owner_id')->nullable()->index();
            $table->string('direction', 16);
            $table->string('driver', 64);
            $table->string('state', 24)->default('pending');
            $table->string('source_name', 255)->nullable();
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->unsignedBigInteger('transferred_bytes')->default(0);
            $table->unsignedInteger('total_chunks')->default(0);
            $table->unsignedInteger('completed_chunks')->default(0);
            $table->decimal('average_bytes_per_second', 20, 2)->nullable();
            $table->unsignedBigInteger('eta_seconds')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->char('idempotency_scope', 64)->nullable();
            $table->char('fingerprint', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['idempotency_scope', 'idempotency_key'], 'storix_transfers_idempotency_unique');
            $table->index(['tenant_id', 'owner_id', 'state'], 'storix_transfers_scope_state_idx');
            $table->index(['state', 'expires_at'], 'storix_transfers_state_expiry_idx');
            $table->index('file_id', 'storix_transfers_file_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storix_transfers');
    }
};
