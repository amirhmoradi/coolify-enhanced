<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('action');
            $table->string('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->json('attributes')->nullable();
            $table->string('scope')->nullable();
            $table->timestamp('event_time');
            $table->timestamps();

            $table->index(['cluster_id', 'event_time']);
            $table->index(['cluster_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_events');
    }
};
