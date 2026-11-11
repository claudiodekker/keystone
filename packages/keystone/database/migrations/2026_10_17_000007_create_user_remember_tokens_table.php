<?php

use ClaudioDekker\Keystone\Database\Collation;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of the remember-me tokens, one row per browser that asked to be remembered.
     */
    public function up(): void
    {
        $user = new (Keystone::userModel());

        Schema::create('user_remember_tokens', function (Blueprint $table) use ($user) {
            $table->id();
            $table->foreignIdFor($user, 'user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->char('digest', 64)->collation(Collation::binary())->unique();
            $table->unsignedBigInteger('epoch');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }
};
