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
        Schema::create('user_remember_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->unsignedBigInteger('credential_epoch');
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();

            $table->unique('token_hash');
            $table->index('user_id');
        });
    }
};
