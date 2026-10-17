<?php

use ClaudioDekker\Keystone\Database\Collation;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of the addresses accounts hold.
     */
    public function up(): void
    {
        $user = new (Keystone::userModel());

        Schema::create('user_emails', function (Blueprint $table) use ($user) {
            $table->id();
            $table->foreignIdFor($user, 'user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->string('address')->collation(Collation::binary());
            $table->string('verified_address')->nullable()->unique()->collation(Collation::binary());
            $table->timestamps();

            $table->unique(['user_id', 'address']);
            $table->index('address');
        });
    }
};
