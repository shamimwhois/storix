<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storix_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 64)->nullable()->index();
            $table->string('owner_id', 64)->nullable()->index();
            $table->foreignUuid('directory_id')->nullable()->constrained('storix_directories')->restrictOnDelete();
            $table->string('driver', 64);
            $table->string('name', 255);
            $table->string('original_name', 255)->nullable();
            $table->string('extension', 32)->nullable();
            $table->string('mime_type', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('sha256', 64)->nullable();
            $table->text('storage_key')->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('visibility', 24)->default('private');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'owner_id', 'created_at'], 'storix_files_scope_created_idx');
            $table->index('directory_id', 'storix_files_directory_idx');
            $table->index(['driver', 'status'], 'storix_files_driver_status_idx');
            $table->index(['tenant_id', 'owner_id', 'driver', 'directory_id', 'sha256', 'size_bytes'], 'storix_files_checksum_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storix_files');
    }
};
