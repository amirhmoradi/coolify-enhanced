<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_configs', function (Blueprint $table) {
            $table->id();
            $table->string('docker_id')->index();
            $table->foreignId('cluster_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->longText('data')->nullable();
            $table->json('labels')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('docker_created_at')->nullable();
            $table->timestamps();

            $table->unique(['cluster_id', 'docker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_configs');
    }
};
