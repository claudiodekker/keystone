<?php

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Models\UserCredential;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of security events for accounts, whose rows outlive the account.
     */
    public function up(): void
    {
        $user = new (Keystone::userModel());

        Schema::create('user_security_events', function (Blueprint $table) use ($user) {
            $table->ulid('id')->primary();
            $table->dateTime('occurred_at', 6);
            $table->string('type');
            $table->foreignIdFor($user, 'user_id')->nullable();
            $table->string('actor');
            $table->string('flow')->nullable();
            $table->string('credential_type')->nullable();
            $table->foreignIdFor(UserCredential::class, 'credential_id')->nullable();
            $table->string('credential_label')->nullable();
            $table->string('reason', 64)->nullable();
            $table->text('ip_address')->nullable();
            $table->text('location')->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('known_device')->nullable();
            $table->char('request_id', 26)->nullable();

            $table->index(['user_id', 'occurred_at']);
        });
    }
};
