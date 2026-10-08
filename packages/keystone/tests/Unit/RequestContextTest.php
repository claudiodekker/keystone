<?php

use ClaudioDekker\Keystone\RequestContext;

it('never loses the readable text of invalid UTF-8, whatever substitute character mbstring is set to', function (string|int $substitute) {
    $previous = mb_substitute_character();
    mb_substitute_character($substitute);

    try {
        expect(RequestContext::clean("Fire\xfffox", 64))->toBe('Fire?fox')
            ->and(RequestContext::clean("\xff\xfe", 64))->toBe('??')
            ->and(mb_substitute_character())->toBe($substitute);
    } finally {
        mb_substitute_character($previous);
    }
})->with([
    'no substitute' => ['none'],
    'the replacement character' => [0xFFFD],
    'the default' => [0x3F],
]);
