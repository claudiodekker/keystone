<?php

use ClaudioDekker\Keystone\Database\Collation;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of the recovery codes accounts hold, one digest per unspent code.
     */
    public function up(): void
    {
        $user = new (Keystone::userModel());

        Schema::create('user_recovery_codes', function (Blueprint $table) use ($user) {
            $table->id();
            $table->foreignIdFor($user, 'user_id')->constrained($user->getTable(), $user->getKeyName())->cascadeOnDelete();
            $table->char('digest', 64)->collation(Collation::binary());
            $table->timestamps();

            $table->unique(['user_id', 'digest']);
        });
    }
};
