<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->boolean('is_encrypted')->default(false)->after('s3_uploaded');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->dropColumn('is_encrypted');
        });
    }
};
