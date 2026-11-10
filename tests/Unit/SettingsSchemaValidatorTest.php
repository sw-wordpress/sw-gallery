<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Core\Domain\Settings\ValidationError;

final class SettingsSchemaValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['columns'],
            'properties' => [
                'columns' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8, 'x-responsive' => true],
                'gap' => ['type' => 'number', 'minimum' => 0.5, 'maximum' => 10, 'default' => 2],
                'mode' => ['type' => 'string', 'enum' => ['cover', 'contain'], 'default' => 'cover'],
                'label' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 5],
                'show' => ['type' => 'boolean'],
                'tags' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 2, 'items' => ['type' => 'string']],
                'border' => [
                    'type' => 'object',
                    'required' => ['width'],
                    'properties' => ['width' => ['type' => 'integer', 'default' => 1]],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return list<string> "code@path"
     */
    private function errors(array $values): array
    {
        $result = (new SettingsSchemaValidator())->validate($this->schema(), $values);

        return array_map(static fn (ValidationError $e): string => $e->code . '@' . $e->path, $result->errors);
    }

    public function testValidValuesGetDefaultsAndPreserveUnknownKeys(): void
    {
        $result = (new SettingsSchemaValidator())->validate(
            $this->schema(),
            ['columns' => 3, 'future' => ['nested' => 1], 'border' => []]
        );

        self::assertTrue($result->isValid());
        self::assertSame([], $result->errorsAsArray());
        self::assertSame(2, $result->values['gap']);
        self::assertSame('cover', $result->values['mode']);
        self::assertSame(['nested' => 1], $result->values['future']);
        self::assertSame(['width' => 1], $result->values['border']);
    }

    public function testResponsiveValuesAreValidatedPerBreakpoint(): void
    {
        $ok = (new SettingsSchemaValidator())->validate($this->schema(), ['columns' => ['base' => 1, 'md' => 3, 'lg' => 4]]);
        self::assertTrue($ok->isValid());

        self::assertSame(
            [
                'swg_above_maximum@/columns/lg',
                'swg_unknown_breakpoint@/columns/tablet',
                'swg_invalid_type@/columns/md',
            ],
            $this->errors(['columns' => ['base' => 1, 'lg' => 9, 'tablet' => 2, 'md' => 'x']])
        );
        self::assertSame(['swg_invalid_type@/columns'], $this->errors(['columns' => []]));
        self::assertSame(['swg_invalid_type@/columns'], $this->errors(['columns' => [1, 2]]));
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $expected
     */
    #[DataProvider('failures')]
    public function testReportsStableCodesAndPaths(array $values, array $expected): void
    {
        self::assertSame($expected, $this->errors($values));
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function failures(): iterable
    {
        yield 'required missing' => [[], ['swg_required@/columns']];
        yield 'wrong integer type' => [['columns' => 1.5], ['swg_invalid_type@/columns']];
        yield 'bool is not integer' => [['columns' => true], ['swg_invalid_type@/columns']];
        yield 'below minimum' => [['columns' => 0], ['swg_below_minimum@/columns']];
        yield 'number type' => [['columns' => 1, 'gap' => '3'], ['swg_invalid_type@/gap']];
        yield 'nan' => [['columns' => 1, 'gap' => NAN], ['swg_invalid_type@/gap']];
        yield 'number below' => [['columns' => 1, 'gap' => 0.1], ['swg_below_minimum@/gap']];
        yield 'number above' => [['columns' => 1, 'gap' => 11], ['swg_above_maximum@/gap']];
        yield 'enum' => [['columns' => 1, 'mode' => 'x'], ['swg_not_in_enum@/mode']];
        yield 'too short' => [['columns' => 1, 'label' => 'a'], ['swg_too_short@/label']];
        yield 'too long counts characters' => [['columns' => 1, 'label' => 'äöüäöü'], ['swg_too_long@/label']];
        yield 'multibyte ok' => [['columns' => 1, 'label' => 'äö'], []];
        yield 'bad utf8' => [['columns' => 1, 'label' => "\xff\xfe"], ['swg_invalid_encoding@/label']];
        yield 'boolean' => [['columns' => 1, 'show' => 'yes'], ['swg_invalid_type@/show']];
        yield 'array not list' => [['columns' => 1, 'tags' => ['a' => 'b']], ['swg_invalid_type@/tags']];
        yield 'too few items' => [['columns' => 1, 'tags' => []], ['swg_too_few_items@/tags']];
        yield 'too many items' => [['columns' => 1, 'tags' => ['a', 'b', 'c']], ['swg_too_many_items@/tags']];
        yield 'item type' => [['columns' => 1, 'tags' => ['a', 5]], ['swg_invalid_type@/tags/1']];
        yield 'object type' => [['columns' => 1, 'border' => [1, 2]], ['swg_invalid_type@/border']];
        yield 'nested' => [['columns' => 1, 'border' => ['width' => 'x']], ['swg_invalid_type@/border/width']];
        yield 'multiple errors' => [['columns' => 0, 'mode' => 5], ['swg_below_minimum@/columns', 'swg_invalid_type@/mode']];
    }

    public function testNestedRequiredAndPointerEscaping(): void
    {
        $schema = [
            'properties' => ['a/b~c' => ['type' => 'object', 'required' => ['x'], 'properties' => ['x' => ['type' => 'string']]]],
        ];
        $result = (new SettingsSchemaValidator())->validate($schema, ['a/b~c' => []]);

        self::assertSame('/a~1b~0c/x', $result->errors[0]->path);
        self::assertSame(
            ['code' => 'swg_required', 'path' => '/a~1b~0c/x', 'message' => 'A required value is missing.'],
            $result->errorsAsArray()[0]
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('brokenSchemas')]
    public function testMalformedSchemaIsAProgrammingError(array $schema): void
    {
        $this->expectException(\LogicException::class);
        (new SettingsSchemaValidator())->validate($schema, ['a' => 1]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function brokenSchemas(): iterable
    {
        yield 'root not object' => [['type' => 'string']];
        yield 'properties not array' => [['properties' => 'x']];
        yield 'property not array' => [['properties' => ['a' => 'x']]];
        yield 'missing type' => [['properties' => ['a' => []]]];
        yield 'unknown type' => [['properties' => ['a' => ['type' => 'null']]]];
        yield 'responsive object' => [['properties' => ['a' => ['type' => 'object', 'x-responsive' => true]]]];
    }
}
