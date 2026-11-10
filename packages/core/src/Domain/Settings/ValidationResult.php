<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Settings;

/**
 * Outcome of a schema validation. `values` is the input with schema defaults applied; keys unknown to the
 * schema (for example written by a newer plugin version) are preserved untouched.
 */
final class ValidationResult
{
    /**
     * @param array<string, mixed>  $values
     * @param list<ValidationError> $errors
     */
    public function __construct(
        public readonly array $values,
        public readonly array $errors = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return list<array{code: string, path: string, message: string}>
     */
    public function errorsAsArray(): array
    {
        return array_map(static fn (ValidationError $e): array => $e->toArray(), $this->errors);
    }
}
