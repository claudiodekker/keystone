<?php

use ClaudioDekker\Keystone\Database\Collation;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of the browsers each account signed in from, one row per account and device.
     */
    public function up(): void
    {
        $user = new (Keystone::userModel());

        Schema::create('user_known_devices', function (Blueprint $table) use ($user) {
            $table->id();
            $table->foreignIdFor($user, 'user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->char('digest', 64)->collation(Collation::binary())->index();
            $table->char('device_digest', 64)->collation(Collation::binary());
            $table->text('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();

            $table->unique(['user_id', 'device_digest']);
        });
    }
};
