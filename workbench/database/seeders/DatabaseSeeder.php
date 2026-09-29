<?php

namespace Workbench\Database\Seeders;

use ClaudioDekker\Keystone\Credentials;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed an account that signs in as jane@example.com with the password "password".
     */
    public function run(CredentialTypes $types): void
    {
        $account = User::create(['name' => 'Jane']);

        DB::table('user_emails')->insert([
            'user_id' => $account->getKey(),
            'address' => 'jane@example.com',
            'verified_at' => now(),
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new Credentials($account))->store(
            $account,
            $types->find('password', Surface::SIGN_IN),
            identifier: null,
            secret: Hash::make('password'),
        );
    }
}
