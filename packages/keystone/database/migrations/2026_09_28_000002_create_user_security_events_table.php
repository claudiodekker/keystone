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
        Schema::create('user_security_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');
            $table->string('type', 64);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('actor', 16);
            $table->string('flow', 32)->nullable();
            $table->string('credential_type', 64)->nullable();
            $table->unsignedBigInteger('credential_id')->nullable();
            $table->string('credential_label', 64)->nullable();
            $table->string('reason', 64)->nullable();
            $table->text('ip_address')->nullable();
            $table->text('location')->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('known_device')->nullable();
            $table->string('request_id', 64)->nullable();

            $table->index(['user_id', 'id']);
            $table->index('occurred_at');
        });
    }
};
