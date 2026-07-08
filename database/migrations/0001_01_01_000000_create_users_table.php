<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // ── Web vs Mobile (order booker) distinction ────────────
            $table->enum('user_type', ['web', 'mobile'])->default('web');
            $table->enum('mobile_role', ['booker', 'delivery_manager'])->nullable();

            // ── Mobile / booker identity ─────────────────────────────
            $table->string('phone', 20)->nullable()->unique();
            $table->string('cnic', 20)->nullable();
            $table->string('employee_code', 50)->nullable(); // Company-issued booker ID
            $table->string('assigned_area', 150)->nullable();

            // ── Device binding (single active session enforcement) ──
            $table->string('device_id')->nullable();
            $table->text('fcm_token')->nullable(); // push notifications (sync nudges)
            $table->string('app_version', 20)->nullable();

            // ── Activity tracking ─────────────────────────────────────
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_active_at')->nullable();

            $table->string('profile_photo')->nullable();

            $table->boolean('is_active')->default(true);
            $table->rememberToken();

            // ── Audit ──────────────────────────────────────────────────
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            // Self-referencing FKs — safe since users table already exists at this point
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('user_type');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};