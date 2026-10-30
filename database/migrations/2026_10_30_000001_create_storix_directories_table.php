<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storix_directories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 64)->nullable()->index();
            $table->string('owner_id', 64)->nullable()->index();
            $table->foreignUuid('parent_id')->nullable()->constrained('storix_directories')->restrictOnDelete();
            $table->string('name', 255);
            $table->string('path_key', 1024);
            $table->char('scope_hash', 64);
            $table->char('name_hash', 64);
            $table->string('driver', 64);
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'owner_id', 'driver'], 'storix_directories_scope_driver_idx');
            $table->unique(['scope_hash', 'name_hash'], 'storix_directories_scope_name_unique');
            $table->index(['tenant_id', 'owner_id', 'driver', 'parent_id', 'name'], 'storix_directories_parent_name_idx');
            $table->index('status', 'storix_directories_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storix_directories');
    }
};
