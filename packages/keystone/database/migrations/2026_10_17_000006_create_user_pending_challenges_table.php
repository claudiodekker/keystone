<?php

use ClaudioDekker\Keystone\Keystone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of the pending challenges, held from browsers that aren't a known device of their account.
     */
    public function up(): void
    {
        $user = new (Keystone::userModel());

        Schema::create('user_pending_challenges', function (Blueprint $table) use ($user) {
            $table->id();
            $table->foreignIdFor($user, 'user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->text('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->index();
        });
    }
};
