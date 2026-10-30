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
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('owner_id')->nullable()->index();
            $table->foreignUuid('parent_id')->nullable()->constrained('storix_directories')->restrictOnDelete();
            $table->string('name', 255);
            $table->string('path_key', 1024);
            $table->string('driver', 64);
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'owner_id', 'driver'], 'storix_directories_scope_driver_idx');
            $table->index('status', 'storix_directories_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storix_directories');
    }
};
