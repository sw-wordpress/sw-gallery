<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Settings;

/**
 * Strict validator for a small JSON-schema subset, without dependencies.
 *
 * Supported keywords: type (string, integer, number, boolean, array, object), enum, minimum, maximum,
 * minLength, maxLength, minItems, maxItems, items, properties, required, default and the extension
 * "x-responsive": true. A responsive property accepts either a plain value or an object keyed by breakpoint
 * (base, sm, md, lg, xl), each value validated against the same schema.
 *
 * Unknown keys of objects are preserved and never reported. A malformed schema is a programming error and
 * raises a LogicException; invalid values are reported in the result, never thrown.
 */
final class SettingsSchemaValidator
{
    public const BREAKPOINTS = ['base', 'sm', 'md', 'lg', 'xl'];

    public const ERROR_TYPE = 'swg_invalid_type';
    public const ERROR_REQUIRED = 'swg_required';
    public const ERROR_ENUM = 'swg_not_in_enum';
    public const ERROR_MINIMUM = 'swg_below_minimum';
    public const ERROR_MAXIMUM = 'swg_above_maximum';
    public const ERROR_MIN_LENGTH = 'swg_too_short';
    public const ERROR_MAX_LENGTH = 'swg_too_long';
    public const ERROR_MIN_ITEMS = 'swg_too_few_items';
    public const ERROR_MAX_ITEMS = 'swg_too_many_items';
    public const ERROR_ENCODING = 'swg_invalid_encoding';
    public const ERROR_BREAKPOINT = 'swg_unknown_breakpoint';

    private const TYPES = ['string', 'integer', 'number', 'boolean', 'array', 'object'];

    /**
     * @param array<string, mixed> $schema An object schema (its "type" may be omitted).
     * @param array<string, mixed> $values
     */
    public function validate(array $schema, array $values): ValidationResult
    {
        if (($schema['type'] ?? 'object') !== 'object') {
            throw new \LogicException('The root of a settings schema must be an object.');
        }
        $errors = [];
        $normalized = $this->validateObject($schema, $values, '', $errors);

        return new ValidationResult($normalized, $errors);
    }

    /**
     * @param array<mixed>          $schema
     * @param array<mixed>          $value
     * @param list<ValidationError> $errors
     * @return array<string, mixed>
     */
    private function validateObject(array $schema, array $value, string $path, array &$errors): array
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        if (!is_array($properties) || !is_array($required)) {
            throw new \LogicException('The "properties" and "required" keywords must be arrays.');
        }

        /** @var array<string, mixed> $result */
        $result = $value;
        foreach ($properties as $key => $propertySchema) {
            $key = (string) $key;
            if (!is_array($propertySchema)) {
                throw new \LogicException('A property schema must be an object.');
            }
            $pointer = $path . '/' . $this->escape($key);
            if (!array_key_exists($key, $value)) {
                if (array_key_exists('default', $propertySchema)) {
                    $result[$key] = $propertySchema['default'];
                } elseif (in_array($key, $required, true)) {
                    $errors[] = new ValidationError(self::ERROR_REQUIRED, $pointer, 'A required value is missing.');
                }
                continue;
            }
            $result[$key] = $this->validateNode($propertySchema, $value[$key], $pointer, $errors);
        }

        return $result;
    }

    /**
     * @param array<mixed>          $schema
     * @param list<ValidationError> $errors
     */
    private function validateNode(array $schema, mixed $value, string $path, array &$errors): mixed
    {
        $type = $schema['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw new \LogicException('A schema node needs a supported "type".');
        }

        if (($schema['x-responsive'] ?? false) === true) {
            if ($type === 'array' || $type === 'object') {
                throw new \LogicException('Responsive values require a scalar type.');
            }

            return $this->validateResponsive($schema, $value, $path, $errors);
        }

        if (!$this->matchesType($type, $value)) {
            $errors[] = new ValidationError(self::ERROR_TYPE, $path, sprintf('The value must be of type %s.', $type));

            return $value;
        }

        return match ($type) {
            'object' => $this->validateObject($schema, (array) $value, $path, $errors),
            'array' => $this->validateArray($schema, (array) $value, $path, $errors),
            'string' => $this->validateString($schema, (string) $value, $path, $errors),
            'integer', 'number' => $this->validateNumber($schema, $value, $path, $errors),
            default => $this->validateEnum($schema, $value, $path, $errors),
        };
    }

    /**
     * @param array<mixed>          $schema
     * @param list<ValidationError> $errors
     */
    private function validateResponsive(array $schema, mixed $value, string $path, array &$errors): mixed
    {
        unset($schema['x-responsive']);
        if (!is_array($value)) {
            return $this->validateNode($schema, $value, $path, $errors);
        }
        if ($value === [] || array_is_list($value)) {
            $errors[] = new ValidationError(
                self::ERROR_TYPE,
                $path,
                'A responsive value must be a plain value or an object.'
            );

            return $value;
        }
        foreach ($value as $breakpoint => $entry) {
            $pointer = $path . '/' . $this->escape((string) $breakpoint);
            if (!in_array($breakpoint, self::BREAKPOINTS, true)) {
                $errors[] = new ValidationError(self::ERROR_BREAKPOINT, $pointer, 'The breakpoint is not supported.');
                continue;
            }
            $value[$breakpoint] = $this->validateNode($schema, $entry, $pointer, $errors);
        }

        return $value;
    }

    private function matchesType(string $type, mixed $value): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || (is_float($value) && is_finite($value)),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            default => is_array($value) && ($value === [] || !array_is_list($value)),
        };
    }

    /**
     * @param array<mixed>          $schema
     * @param list<ValidationError> $errors
     */
    private function validateString(array $schema, string $value, string $path, array &$errors): string
    {
        $length = preg_match_all('/./su', $value);
        if ($length === false) {
            $errors[] = new ValidationError(self::ERROR_ENCODING, $path, 'The value is not valid UTF-8.');

            return $value;
        }
        $min = $schema['minLength'] ?? null;
        $max = $schema['maxLength'] ?? null;
        if (is_int($min) && $length < $min) {
            $errors[] = new ValidationError(
                self::ERROR_MIN_LENGTH,
                $path,
                sprintf('The value needs at least %d characters.', $min)
            );
        }
        if (is_int($max) && $length > $max) {
            $errors[] = new ValidationError(
                self::ERROR_MAX_LENGTH,
                $path,
                sprintf('The value allows at most %d characters.', $max)
            );
        }
        $this->validateEnum($schema, $value, $path, $errors);

        return $value;
    }

    /**
     * @param array<mixed>          $schema
     * @param list<ValidationError> $errors
     */
    private function validateNumber(array $schema, int|float $value, string $path, array &$errors): int|float
    {
        $min = $schema['minimum'] ?? null;
        $max = $schema['maximum'] ?? null;
        if ((is_int($min) || is_float($min)) && $value < $min) {
            $errors[] = new ValidationError(
                self::ERROR_MINIMUM,
                $path,
                sprintf('The value must be at least %s.', $min)
            );
        }
        if ((is_int($max) || is_float($max)) && $value > $max) {
            $errors[] = new ValidationError(
                self::ERROR_MAXIMUM,
                $path,
                sprintf('The value must be at most %s.', $max)
            );
        }
        $this->validateEnum($schema, $value, $path, $errors);

        return $value;
    }

    /**
     * @param array<mixed>          $schema
     * @param array<mixed>          $value
     * @param list<ValidationError> $errors
     * @return array<mixed>
     */
    private function validateArray(array $schema, array $value, string $path, array &$errors): array
    {
        $min = $schema['minItems'] ?? null;
        $max = $schema['maxItems'] ?? null;
        if (is_int($min) && count($value) < $min) {
            $errors[] = new ValidationError(
                self::ERROR_MIN_ITEMS,
                $path,
                sprintf('At least %d entries are needed.', $min)
            );
        }
        if (is_int($max) && count($value) > $max) {
            $errors[] = new ValidationError(
                self::ERROR_MAX_ITEMS,
                $path,
                sprintf('At most %d entries are allowed.', $max)
            );
        }
        $items = $schema['items'] ?? null;
        if (is_array($items)) {
            foreach ($value as $index => $entry) {
                $value[$index] = $this->validateNode($items, $entry, $path . '/' . $index, $errors);
            }
        }

        return $value;
    }

    /**
     * @param array<mixed>          $schema
     * @param list<ValidationError> $errors
     */
    private function validateEnum(array $schema, mixed $value, string $path, array &$errors): mixed
    {
        $enum = $schema['enum'] ?? null;
        if (is_array($enum) && !in_array($value, $enum, true)) {
            $errors[] = new ValidationError(self::ERROR_ENUM, $path, 'The value is not one of the allowed options.');
        }

        return $value;
    }

    private function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
