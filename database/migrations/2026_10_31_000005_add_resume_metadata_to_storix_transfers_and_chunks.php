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
            $table->text('multipart_upload_id')->nullable()->after('fingerprint');
            $table->timestamp('multipart_started_at')->nullable()->after('multipart_upload_id');
        });

        Schema::table('storix_chunks', function (Blueprint $table): void {
            $table->timestamp('verified_at')->nullable()->after('last_error_code');
        });
    }

    public function down(): void
    {
        Schema::table('storix_chunks', function (Blueprint $table): void {
            $table->dropColumn('verified_at');
        });

        Schema::table('storix_transfers', function (Blueprint $table): void {
            $table->dropColumn(['multipart_upload_id', 'multipart_started_at']);
        });
    }
};
