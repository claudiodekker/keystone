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
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('credential_epoch')->default(0);
            $table->timestamp('credential_epoch_moved_at')->nullable();
            $table->boolean('has_second_factor')->default(false);
            $table->boolean('has_recovery_codes')->default(false);
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();

            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }

            foreach (['email' => 255, 'password' => 255, 'remember_token' => 100] as $column => $length) {
                if (Schema::hasColumn('users', $column)) {
                    $table->string($column, $length)->nullable()->change();
                }
            }
        });
    }
};
