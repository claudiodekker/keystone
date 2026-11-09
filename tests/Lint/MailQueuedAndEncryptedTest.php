<?php

use ClaudioDekker\Keystone\Lint\Checks\MailQueuedAndEncrypted;
use ClaudioDekker\LaravelGuardrails\Kind;
use ClaudioDekker\LaravelGuardrails\Testing\TestFile;

it('fails a notification that is not queued', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Notifications/ExampleAlert.php', <<<'PHP'
        <?php

        use Illuminate\Contracts\Queue\ShouldBeEncrypted;
        use Illuminate\Notifications\Notification;

        class ExampleAlert extends Notification implements ShouldBeEncrypted
        {
        }
        PHP);

    expect($file->lines(new MailQueuedAndEncrypted))->toBe([6]);
});

it('fails a notification that is not encrypted on the queue', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Notifications/ExampleAlert.php', <<<'PHP'
        <?php

        use Illuminate\Contracts\Queue\ShouldQueue;
        use Illuminate\Notifications\Notification;

        class ExampleAlert extends Notification implements ShouldQueue
        {
        }
        PHP);

    expect($file->lines(new MailQueuedAndEncrypted))->toBe([6]);
});

it('fails a mailable that is neither queued nor encrypted', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone-magic-link/src/Mail/ExampleLink.php', <<<'PHP'
        <?php

        use Illuminate\Mail\Mailable;

        class ExampleLink extends Mailable
        {
        }
        PHP);

    expect($file->lines(new MailQueuedAndEncrypted))->toBe([5]);
});

it('passes a notification that is queued and encrypted', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Notifications/ExampleAlert.php', <<<'PHP'
        <?php

        use Illuminate\Contracts\Queue\ShouldBeEncrypted;
        use Illuminate\Contracts\Queue\ShouldQueue;
        use Illuminate\Notifications\Notification;

        class ExampleAlert extends Notification implements ShouldBeEncrypted, ShouldQueue
        {
        }
        PHP);

    expect($file->lines(new MailQueuedAndEncrypted))->toBe([]);
});

it('reads only the source code of the packages', function () {
    $file = TestFile::of(Kind::TESTS, 'packages/keystone/app-tests/Support/ExampleAlert.php', <<<'PHP'
        <?php

        use Illuminate\Notifications\Notification;

        class ExampleAlert extends Notification
        {
        }
        PHP);

    expect($file->lines(new MailQueuedAndEncrypted))->toBe([]);
});
