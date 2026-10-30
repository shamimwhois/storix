<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storix_transfers', function (Blueprint $table): void {
            $table->text('multipart_upload_id')->nullable()->after('fingerprint');
            $table->timestamp('multipart_started_at')->nullable()->after('multipart_upload_id');
            $table->string('resume_lease_token', 64)->nullable()->after('multipart_started_at');
            $table->timestamp('resume_lease_expires_at')->nullable()->after('resume_lease_token');
            $table->index(['state', 'resume_lease_expires_at'], 'storix_transfers_resume_lease_index');
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
            $table->dropIndex('storix_transfers_resume_lease_index');
            $table->dropColumn([
                'multipart_upload_id',
                'multipart_started_at',
                'resume_lease_token',
                'resume_lease_expires_at',
            ]);
        });
    }
};
