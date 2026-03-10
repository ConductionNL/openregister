<?php

declare(strict_types=1);

/**
 * PropertyValidatorHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use Exception;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PropertyValidatorHandler
 *
 * Tests JSON Schema property validation logic.
 */
class PropertyValidatorHandlerTest extends TestCase
{
    /** @var PropertyValidatorHandler */
    private PropertyValidatorHandler $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PropertyValidatorHandler();
    }

    // =========================================================================
    // validateProperty - type validation
    // =========================================================================

    public function testValidatePropertyRequiresType(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("must have a 'type' field");

        $this->validator->validateProperty(['title' => 'Name']);
    }

    public function testValidatePropertyRejectsInvalidType(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid type');

        $this->validator->validateProperty(['type' => 'invalid_type']);
    }

    /**
     * @dataProvider validTypesProvider
     */
    public function testValidatePropertyAcceptsValidTypes(string $type): void
    {
        $result = $this->validator->validateProperty(['type' => $type]);

        $this->assertTrue($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validTypesProvider(): array
    {
        return [
            'string'  => ['string'],
            'number'  => ['number'],
            'integer' => ['integer'],
            'boolean' => ['boolean'],
            'array'   => ['array'],
            'object'  => ['object'],
            'null'    => ['null'],
            'file'    => ['file'],
        ];
    }

    // =========================================================================
    // validateProperty - string format validation
    // =========================================================================

    public function testValidatePropertyAcceptsValidStringFormats(): void
    {
        $validFormats = ['date-time', 'date', 'email', 'uri', 'uuid', 'url', 'semver', 'color'];

        foreach ($validFormats as $format) {
            $result = $this->validator->validateProperty([
                'type'   => 'string',
                'format' => $format,
            ]);
            $this->assertTrue($result, "Format '$format' should be valid");
        }
    }

    public function testValidatePropertyRejectsInvalidStringFormat(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid string format');

        $this->validator->validateProperty([
            'type'   => 'string',
            'format' => 'totally-invalid-format',
        ]);
    }

    public function testValidatePropertyIgnoresFormatOnNonString(): void
    {
        // Format should be ignored on non-string types.
        $result = $this->validator->validateProperty([
            'type'   => 'integer',
            'format' => 'totally-invalid-format',
        ]);

        $this->assertTrue($result);
    }

    // =========================================================================
    // validateProperty - numeric constraints
    // =========================================================================

    public function testValidatePropertyNumericMinMax(): void
    {
        $result = $this->validator->validateProperty([
            'type'    => 'integer',
            'minimum' => 0,
            'maximum' => 100,
        ]);

        $this->assertTrue($result);
    }

    public function testValidatePropertyNonNumericMinimumThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("'minimum' at '' must be numeric");

        $this->validator->validateProperty([
            'type'    => 'number',
            'minimum' => 'abc',
        ]);
    }

    public function testValidatePropertyNonNumericMaximumThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("'maximum' at '' must be numeric");

        $this->validator->validateProperty([
            'type'    => 'integer',
            'maximum' => 'xyz',
        ]);
    }

    public function testValidatePropertyMinimumGreaterThanMaximumThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("'minimum' cannot be greater than 'maximum'");

        $this->validator->validateProperty([
            'type'    => 'number',
            'minimum' => 100,
            'maximum' => 10,
        ]);
    }

    // =========================================================================
    // validateProperty - enum validation
    // =========================================================================

    public function testValidatePropertyAcceptsEnum(): void
    {
        $result = $this->validator->validateProperty([
            'type' => 'string',
            'enum' => ['red', 'green', 'blue'],
        ]);

        $this->assertTrue($result);
    }

    public function testValidatePropertyRejectsEmptyEnum(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("'enum' at '' must be a non-empty array");

        $this->validator->validateProperty([
            'type' => 'string',
            'enum' => [],
        ]);
    }

    public function testValidatePropertyRejectsNonArrayEnum(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("'enum' at '' must be a non-empty array");

        $this->validator->validateProperty([
            'type' => 'string',
            'enum' => 'not-an-array',
        ]);
    }

    // =========================================================================
    // validateProperty - boolean flags
    // =========================================================================

    public function testValidatePropertyRejectsNonBooleanVisible(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("'visible' at '' must be a boolean");

        $this->validator->validateProperty([
            'type'    => 'string',
            'visible' => 'yes',
        ]);
    }

    public function testValidatePropertyRejectsNonBooleanHideOnCollection(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("'hideOnCollection' at '' must be a boolean");

        $this->validator->validateProperty([
            'type'             => 'string',
            'hideOnCollection' => 1,
        ]);
    }

    public function testValidatePropertyRejectsNonBooleanHideOnForm(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("'hideOnForm' at '' must be a boolean");

        $this->validator->validateProperty([
            'type'       => 'string',
            'hideOnForm' => 'true',
        ]);
    }

    // =========================================================================
    // validateProperty - onDelete validation
    // =========================================================================

    public function testValidatePropertyOnDeleteRequiresRef(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("'onDelete' at '' is only valid on relation properties");

        $this->validator->validateProperty([
            'type'     => 'string',
            'onDelete' => 'CASCADE',
        ]);
    }

    public function testValidatePropertyOnDeleteAcceptsValidActions(): void
    {
        $validActions = ['CASCADE', 'RESTRICT', 'SET_NULL', 'SET_DEFAULT', 'NO_ACTION'];

        foreach ($validActions as $action) {
            $result = $this->validator->validateProperty([
                'type'     => 'string',
                '$ref'     => 'some-schema',
                'onDelete' => $action,
            ]);
            $this->assertTrue($result, "onDelete action '$action' should be valid");
        }
    }

    public function testValidatePropertyOnDeleteAcceptsLowercase(): void
    {
        $result = $this->validator->validateProperty([
            'type'     => 'string',
            '$ref'     => 'some-schema',
            'onDelete' => 'cascade',
        ]);

        $this->assertTrue($result);
    }

    public function testValidatePropertyOnDeleteRejectsInvalidAction(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid onDelete value');

        $this->validator->validateProperty([
            'type'     => 'string',
            '$ref'     => 'some-schema',
            'onDelete' => 'INVALID',
        ]);
    }

    // =========================================================================
    // validateProperty - nested properties
    // =========================================================================

    public function testValidatePropertyNestedObject(): void
    {
        $result = $this->validator->validateProperty([
            'type'       => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age'  => ['type' => 'integer'],
            ],
        ]);

        $this->assertTrue($result);
    }

    public function testValidatePropertyNestedArrayItems(): void
    {
        $result = $this->validator->validateProperty([
            'type'  => 'array',
            'items' => ['type' => 'string'],
        ]);

        $this->assertTrue($result);
    }

    public function testValidatePropertyArrayItemsWithRefSkipsItemValidation(): void
    {
        // Array items with $ref should not be validated as standalone properties.
        $result = $this->validator->validateProperty([
            'type'  => 'array',
            'items' => ['$ref' => 'some-schema'],
        ]);

        $this->assertTrue($result);
    }

    public function testValidatePropertyOneOf(): void
    {
        $result = $this->validator->validateProperty([
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ]);

        $this->assertTrue($result);
    }

    // =========================================================================
    // validateProperties
    // =========================================================================

    public function testValidatePropertiesMultiple(): void
    {
        $result = $this->validator->validateProperties([
            'name'  => ['type' => 'string'],
            'count' => ['type' => 'integer'],
            'tags'  => ['type' => 'array', 'items' => ['type' => 'string']],
        ]);

        $this->assertTrue($result);
    }

    public function testValidatePropertiesRejectsNonArrayProperty(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("must be an object");

        $this->validator->validateProperties([
            'name' => 'not-an-array',
        ]);
    }

    public function testValidatePropertiesReportsPathCorrectly(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("/props/badField");

        $this->validator->validateProperties([
            'goodField' => ['type' => 'string'],
            'badField'  => ['type' => 'totally_invalid_type'],
        ], '/props');
    }
}
