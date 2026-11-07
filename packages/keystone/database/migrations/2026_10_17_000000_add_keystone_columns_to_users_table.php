<?php

use ClaudioDekker\Keystone\Accounts\CredentialEpoch;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add Keystone's columns to the app's users table.
     */
    public function up(): void
    {
        $user = new (Keystone::userModel());

        Schema::table($user->getTable(), function (Blueprint $table) {
            $table->unsignedBigInteger(CredentialEpoch::COLUMN)->default(0);
        });
    }
};
