<?php

namespace Workbench\Database\Seeders;

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\Password\AppTests\PasswordSupport;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Create Ada, who signs in with her verified address and the fixture password.
     */
    public function run(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);

        UserEmail::factory()
            ->for($user, 'user')
            ->verified()
            ->create(['address' => 'ada@example.com']);

        (new PasswordSupport)->arrangeCredential(Surface::SIGN_IN, $user);
    }
}
