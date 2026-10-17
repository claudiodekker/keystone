<?php

use ClaudioDekker\Keystone\Keystone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the one table every credential type keeps its credentials in.
     */
    public function up(): void
    {
        $user = new (Keystone::userModel());

        Schema::create('user_credentials', function (Blueprint $table) use ($user) {
            $table->id();
            $table->foreignIdFor($user, 'user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->string('type');
            $table->text('secret')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }
};
