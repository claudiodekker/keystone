<?php

use ClaudioDekker\Keystone\Lint\Checks\CredentialWritesThroughAccountChange;
use ClaudioDekker\LaravelGuardrails\Kind;
use ClaudioDekker\LaravelGuardrails\Testing\TestFile;

it('fails every write in source code that names the credential model', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Example.php', <<<'PHP'
        <?php

        use ClaudioDekker\Keystone\Models\UserCredential;

        UserCredential::create(['type' => 'password']);
        UserCredential::query()->whereKey(1)->update(['secret' => 'x']);
        UserCredential::query()->whereKey(1)->delete();
        $credential->save();
        $credential?->forceDelete();
        UserCredential::query()->upsert([], ['id']);
        PHP);

    expect($file->lines(new CredentialWritesThroughAccountChange))->toBe([5, 6, 7, 8, 9, 10]);
});

it('fails a write in source code that names the credential model under an alias', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Example.php', <<<'PHP'
        <?php

        use ClaudioDekker\Keystone\Models\UserCredential as Credential;

        Credential::query()->whereKey(1)->increment('uses');
        PHP);

    expect($file->lines(new CredentialWritesThroughAccountChange))->toBe([5]);
});

it('passes reads of the credential model', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Example.php', <<<'PHP'
        <?php

        use ClaudioDekker\Keystone\Models\UserCredential;

        UserCredential::query()->where('user_id', 1)->get();
        UserCredential::query()->find(1);
        $credential->toCredential();
        PHP);

    expect($file->lines(new CredentialWritesThroughAccountChange))->toBe([]);
});

it('passes writes in source code that never names the credential model', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Example.php', <<<'PHP'
        <?php

        use ClaudioDekker\Keystone\Models\UserSecurityEvent;

        $entry->saveQuietly();
        UserSecurityEvent::query()->delete();
        PHP);

    expect($file->lines(new CredentialWritesThroughAccountChange))->toBe([]);
});
