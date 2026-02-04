<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_global_admin')) {
                $table->boolean('is_global_admin')->default(false)->after('email');
            }
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('active')->after('is_global_admin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_global_admin', 'status']);
        });
    }
};
