<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Support;

use CristeaIulian\AiOtel\Contracts\Redactor;
use JsonSerializable;
use Stringable;

/**
 * Applies the content capture policy: opt-in, truncation and redaction.
 *
 * Every string that could carry prompt or completion content passes through
 * here before it is handed to a recorder. When capture is disabled, every
 * method returns null so nothing is recorded.
 */
final class ContentCapture
{
    public const TRUNCATION_MARKER = '…[truncated]';

    public function __construct(
        private readonly bool $enabled,
        private readonly int $maxLength = 8192,
        private readonly ?Redactor $redactor = null,
    ) {}

    public static function disabled(): self
    {
        return new self(false);
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Capture a single string of content.
     */
    public function text(?string $value, string $attribute): ?string
    {
        if (! $this->enabled || $value === null) {
            return null;
        }

        return $this->scrub($value, $attribute);
    }

    /**
     * Capture a value of any type as a string (tool results, for example).
     */
    public function scalar(mixed $value, string $attribute): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        return $this->scrub($this->stringify($value), $attribute);
    }

    /**
     * Capture a nested structure, scrubbing every string inside it while
     * preserving the structure itself.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public function structure(array $value, string $attribute): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        return $this->walk($value, $attribute);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function walk(array $value, string $attribute): array
    {
        foreach ($value as $key => $item) {
            $value[$key] = match (true) {
                is_string($item) => $this->scrub($item, $attribute),
                is_array($item) => $this->walk($item, $attribute),
                $item instanceof Stringable => $this->scrub((string) $item, $attribute),
                is_object($item) => $this->walk($this->objectToArray($item), $attribute),
                default => $item,
            };
        }

        return $value;
    }

    private function scrub(string $value, string $attribute): string
    {
        if ($this->redactor !== null) {
            $value = $this->redactor->redact($value, $attribute);
        }

        if ($this->maxLength > 0 && mb_strlen($value) > $this->maxLength) {
            $value = mb_substr($value, 0, $this->maxLength).self::TRUNCATION_MARKER;
        }

        return $value;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            $value instanceof Stringable => (string) $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '',
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    private function objectToArray(object $object): array
    {
        $encoded = json_encode(
            $object instanceof JsonSerializable ? $object->jsonSerialize() : get_object_vars($object),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        $decoded = $encoded === false ? null : json_decode($encoded, true);

        return is_array($decoded) ? $decoded : [];
    }
}
