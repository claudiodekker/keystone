<?php

use ClaudioDekker\Keystone\Lint\Checks\NoConfigDefaults;
use ClaudioDekker\Keystone\Lint\File;

it('fails a config read with an inline default', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Config;

        config('keystone.limit', 5);
        config()->get('keystone.limit', 5);
        config()->string('keystone.name', 'Keystone');
        Config::integer('keystone.limit', 5);
        \Config::get('keystone.limit', 5);
        PHP);

    expect((new NoConfigDefaults)->check($file))->toBe([5, 6, 7, 8, 9]);
});

it('passes a config read without a default, and a config write', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Config;

        config('keystone.limit');
        config(['keystone.limit' => 5]);
        config()->get('keystone.limit');
        config()->set('keystone.limit', 5);
        Config::get('keystone.limit');
        Config::set('keystone.limit', 5);
        $settings->get('keystone.limit', 5);
        PHP);

    expect((new NoConfigDefaults)->check($file))->toBe([]);
});
