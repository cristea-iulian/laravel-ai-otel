<?php

declare(strict_types=1);

use CristeaIulian\AiOtel\Support\ContentCapture;
use CristeaIulian\AiOtel\Tests\Fixtures\MaskingRedactor;

test('disabled capture returns null for everything', function (): void {
    $capture = ContentCapture::disabled();

    expect($capture->enabled())->toBeFalse()
        ->and($capture->text('hello', 'x'))->toBeNull()
        ->and($capture->scalar(['a' => 1], 'x'))->toBeNull()
        ->and($capture->structure(['a' => 'b'], 'x'))->toBeNull();
});

test('enabled capture truncates strings and keeps structure', function (): void {
    $capture = new ContentCapture(enabled: true, maxLength: 5);

    expect($capture->text('abcdefgh', 'x'))->toBe('abcde'.ContentCapture::TRUNCATION_MARKER)
        ->and($capture->text('abc', 'x'))->toBe('abc')
        ->and($capture->structure(['role' => 'user', 'parts' => [['content' => 'abcdefgh', 'n' => 3]]], 'x'))
        ->toBe(['role' => 'user', 'parts' => [['content' => 'abcde'.ContentCapture::TRUNCATION_MARKER, 'n' => 3]]]);
});

test('scalars are stringified', function (): void {
    $capture = new ContentCapture(enabled: true);

    expect($capture->scalar(42, 'x'))->toBe('42')
        ->and($capture->scalar(true, 'x'))->toBe('true')
        ->and($capture->scalar(null, 'x'))->toBe('')
        ->and($capture->scalar(['a' => 'ü'], 'x'))->toBe('{"a":"ü"}')
        ->and($capture->scalar(new class implements Stringable
        {
            public function __toString(): string
            {
                return 'stringable';
            }
        }, 'x'))->toBe('stringable');
});

test('the redactor runs before truncation', function (): void {
    $capture = new ContentCapture(enabled: true, maxLength: 12, redactor: new MaskingRedactor);

    expect($capture->text('a secret here', 'x'))->toBe('a [redacted]'.ContentCapture::TRUNCATION_MARKER);
});
