<?php

test('the bundled list holds the most common passwords, lowercase NFC, one per line and each once, so a lookup trusts it as written', function () {
    $contents = file_get_contents(dirname(__DIR__, 2).'/resources/common-passwords.txt');
    $lines = explode("\n", rtrim($contents, "\n"));
    $normalised = array_map(fn (string $line) => mb_strtolower(Normalizer::normalize($line, Normalizer::FORM_C)), $lines);

    expect(count($lines))->toBeGreaterThan(9900)
        ->and($contents)->toEndWith("\n")->not->toContain("\r")
        ->and($lines)->not->toContain('')
        ->and($normalised)->toBe($lines)
        ->and(array_unique($lines))->toBe($lines);
});
