<?php
// database/migrations/2026_07_08_000002_create_user_activity_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('activity_type', 50); // login, logout, order_created, order_synced, sync_failed, etc.
            $table->string('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('device_id')->nullable();
            $table->string('app_version', 20)->nullable();
            $table->json('meta')->nullable(); // structured extra data (e.g. order_id, sync latency)
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'activity_type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_logs');
    }
};