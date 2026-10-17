<?php

use ClaudioDekker\Keystone\Lint\Checks\ClientReadsThroughRequestContext;
use ClaudioDekker\Keystone\Lint\File;

it('fails a request method that reads the address or the user agent', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        class Example
        {
            public function run($request): void
            {
                $request->ip();
                $request->ips();
                $request?->userAgent();
                $request->getClientIp();
                $request->getClientIps();
            }
        }
        PHP);

    expect((new ClientReadsThroughRequestContext)->check($file))->toBe([7, 8, 9, 10, 11]);
});

it('fails the user agent read as a header, whatever its case', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        class Example
        {
            public function run($request): void
            {
                $request->header('User-Agent');
                $request->hasHeader('user-agent');
                $request->header('Accept');
            }
        }
        PHP);

    expect((new ClientReadsThroughRequestContext)->check($file))->toBe([7, 8]);
});

it('fails the address or user agent read as a server variable or from the superglobal', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        class Example
        {
            public function run($request): void
            {
                $request->server('REMOTE_ADDR');
                $request->server('HTTP_USER_AGENT');
                $request->server('HTTP_X_FORWARDED_FOR');
                $request->server('REQUEST_METHOD');
                $_SERVER['REMOTE_ADDR'];
            }
        }
        PHP);

    expect((new ClientReadsThroughRequestContext)->check($file))->toBe([7, 8, 9, 11]);
});

it('leaves the request context itself alone', function () {
    $file = new File('packages/keystone/src/Http/RequestContext.php', "<?php\n\nclass RequestContext\n{\n    public function run(\$request)\n    {\n        return \$request->ip();\n    }\n}\n");

    expect((new ClientReadsThroughRequestContext)->check($file))->toBe([]);
});

it('reads only src', function () {
    $file = new File('packages/keystone/app-tests/Example.php', "<?php\n\nclass Example\n{\n    public function run(\$request)\n    {\n        return \$request->ip();\n    }\n}\n");

    expect((new ClientReadsThroughRequestContext)->check($file))->toBe([]);
});
