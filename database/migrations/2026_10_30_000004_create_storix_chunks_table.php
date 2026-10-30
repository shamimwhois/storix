<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storix_chunks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_id')->constrained('storix_transfers')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->unsignedBigInteger('offset_bytes');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->nullable();
            $table->string('state', 24)->default('pending');
            $table->text('driver_reference')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['transfer_id', 'chunk_index'], 'storix_chunks_transfer_index_unique');
            $table->index(['transfer_id', 'state'], 'storix_chunks_transfer_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storix_chunks');
    }
};
