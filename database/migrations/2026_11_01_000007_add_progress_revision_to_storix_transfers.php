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
            $table->unsignedBigInteger('progress_revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('storix_transfers', function (Blueprint $table): void {
            $table->dropColumn('progress_revision');
        });
    }
};
