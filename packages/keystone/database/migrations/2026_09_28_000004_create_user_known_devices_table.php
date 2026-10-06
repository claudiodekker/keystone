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
        Schema::create('user_known_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('device_hash', 64);
            $table->char('cookie_hash', 64);
            $table->text('user_agent')->nullable();
            $table->text('ip_address')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'device_hash']);
            $table->index('device_hash');
            $table->index('cookie_hash');
            $table->index('last_seen_at');
        });
    }
};
