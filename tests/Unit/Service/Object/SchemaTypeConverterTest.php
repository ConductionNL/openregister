<?php

/**
 * SchemaTypeConverter Unit Test
 *
 * Pins the contract defined in
 * openspec/specs/schema-driven-read-coercion/spec.md.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SchemaTypeConverter.
 *
 * Each test method maps to a scenario in the schema-driven-read-coercion spec.
 */
class SchemaTypeConverterTest extends TestCase
{

    private SchemaTypeConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new SchemaTypeConverter();
    }//end setUp()

    /*
        ====================================================================
     * String
     * ==================================================================== */

    /**
     * @dataProvider stringCoercionProvider
     */
    public function testStringCoercion(mixed $input, mixed $expected, string $message): void
    {
        $this->assertSame($expected, $this->converter->convertValue($input, 'string'), $message);
    }//end testStringCoercion()

    public static function stringCoercionProvider(): array
    {
        return [
            'numeric string passes through'           => ['45', '45', "string '45' must stay '45'"],
            'integer DB value coerces to string'      => [45, '45', 'int 45 must coerce to string "45"'],
            'float DB value coerces to string'        => [4.5, '4.5', 'float 4.5 must coerce to string "4.5"'],
            'boolean-literal "true" stays string'     => ['true', 'true', '"true" must NOT decode to bool'],
            'boolean-literal "false" stays string'    => ['false', 'false', '"false" must NOT decode to bool'],
            'null-literal "null" stays string'        => ['null', 'null', '"null" must NOT decode to PHP null'],
            'numeric-shaped string stays string'      => ['123', '123', '"123" must NOT decode to int (regression of MagicStatisticsHandler bug)'],
            'quoted-string JSON keeps its quotes'     => ['"foo"', '"foo"', 'escaped string must not be unwrapped'],
            'array-shaped string DECODED for compat'  => ['[1,2,3]', [1, 2, 3], 'array-shaped string under string type decodes for legacy compat'],
            'object-shaped string DECODED for compat' => ['{"k":"v"}', ['k' => 'v'], 'object-shaped string under string type decodes for legacy compat'],
            'plain text passes through'               => ['hello', 'hello', 'plain text stays plain text'],
        ];
    }//end stringCoercionProvider()

    /*
        ====================================================================
     * Boolean
     * ==================================================================== */

    /**
     * @dataProvider booleanCoercionProvider
     */
    public function testBooleanCoercion(mixed $input, bool $expected, string $message): void
    {
        $this->assertSame($expected, $this->converter->convertValue($input, 'boolean'), $message);
    }//end testBooleanCoercion()

    public static function booleanCoercionProvider(): array
    {
        return [
            'native true passes through'           => [true, true, 'PostgreSQL native true returns true'],
            'native false passes through'          => [false, false, 'PostgreSQL native false returns false'],
            'MariaDB int 1 coerces to true'        => [1, true, 'MariaDB TINYINT(1) value 1 must become true'],
            'MariaDB int 0 coerces to false'       => [0, false, 'MariaDB TINYINT(1) value 0 must become false'],
            'string "1" coerces to true'           => ['1', true, 'string "1" is truthy literal'],
            'string "0" coerces to false'          => ['0', false, 'string "0" is not in the truthy set'],
            'string "true" coerces to true'        => ['true', true, 'string "true" is truthy literal'],
            'string "TRUE" (upper) is truthy'      => ['TRUE', true, 'truthy match is case-insensitive'],
            'string "yes" coerces to true'         => ['yes', true, 'string "yes" is truthy literal'],
            'string "no" coerces to false'         => ['no', false, 'string "no" is not truthy'],
            'random string coerces to false'       => ['random string', false, 'unknown string is falsy'],
            'empty string coerces to false'        => ['', false, 'empty string is not in truthy set'],
            'string "on" is FALSE (per design D7)' => ['on', false, "HTML form 'on' is intentionally NOT truthy — see design D7"],
            'float 0.0 coerces to false'           => [0.0, false, '0.0 is falsy via (bool) cast'],
            'float 1.5 coerces to true'            => [1.5, true, '1.5 is truthy via (bool) cast'],
        ];
    }//end booleanCoercionProvider()

    /*
        ====================================================================
     * Integer
     * ==================================================================== */

    /**
     * @dataProvider integerCoercionProvider
     */
    public function testIntegerCoercion(mixed $input, mixed $expected, string $message): void
    {
        $this->assertSame($expected, $this->converter->convertValue($input, 'integer'), $message);
    }//end testIntegerCoercion()

    public static function integerCoercionProvider(): array
    {
        return [
            'numeric string coerces to int' => ['42', 42, 'string "42" must become int 42'],
            'integer passes through'        => [42, 42, 'int 42 stays 42'],
            'float string truncates to int' => ['42.7', 42, 'is_numeric "42.7" truncates via (int) cast'],
            'non-numeric stays unchanged'   => ['not a number', 'not a number', 'non-numeric input passes through for downstream validation'],
        ];
    }//end integerCoercionProvider()

    /*
        ====================================================================
     * Number
     * ==================================================================== */

    /**
     * @dataProvider numberCoercionProvider
     */
    public function testNumberCoercion(mixed $input, mixed $expected, string $message): void
    {
        $this->assertSame($expected, $this->converter->convertValue($input, 'number'), $message);
    }//end testNumberCoercion()

    public static function numberCoercionProvider(): array
    {
        return [
            'integer DB value coerces to float' => [7, 7.0, 'int 7 must become float 7.0'],
            'decimal string coerces to float'   => ['3.14', 3.14, 'string "3.14" must become float 3.14'],
            'integer string coerces to float'   => ['7', 7.0, 'string "7" must become float 7.0'],
            'non-numeric stays unchanged'       => ['not a number', 'not a number', 'non-numeric input passes through for downstream validation'],
        ];
    }//end numberCoercionProvider()

    /*
        ====================================================================
     * Array / Object
     * ==================================================================== */

    /**
     * @dataProvider arrayCoercionProvider
     */
    public function testArrayCoercion(mixed $input, mixed $expected, string $message): void
    {
        $this->assertSame($expected, $this->converter->convertValue($input, 'array'), $message);
    }//end testArrayCoercion()

    /**
     * @dataProvider arrayCoercionProvider
     */
    public function testObjectCoercion(mixed $input, mixed $expected, string $message): void
    {
        // Object schema type behaves identically to array per design and existing helper.
        $this->assertSame($expected, $this->converter->convertValue($input, 'object'), $message);
    }//end testObjectCoercion()

    public static function arrayCoercionProvider(): array
    {
        return [
            'JSON-string array decodes'                 => ['[1,2,3]', [1, 2, 3], 'JSON-string array column decodes'],
            'JSON-string object decodes'                => ['{"a":1}', ['a' => 1], 'JSON-string object column decodes'],
            'already-array passes through'              => [['k' => 'v'], ['k' => 'v'], 'pre-decoded array passes through'],
            'already-list passes through'               => [[1, 2, 3], [1, 2, 3], 'pre-decoded list passes through'],
            'invalid JSON returns string for validator' => ['not json', 'not json', 'failing decode returns original string'],
        ];
    }//end arrayCoercionProvider()

    /*
        ====================================================================
     * Null
     * ==================================================================== */

    /**
     * @dataProvider nullPreservationProvider
     */
    public function testNullPreserved(string $schemaType): void
    {
        $this->assertNull(
            $this->converter->convertValue(null, $schemaType),
            "null must be preserved for schema type '$schemaType'"
        );
    }//end testNullPreserved()

    public static function nullPreservationProvider(): array
    {
        return [
            'string'  => ['string'],
            'boolean' => ['boolean'],
            'integer' => ['integer'],
            'number'  => ['number'],
            'array'   => ['array'],
            'object'  => ['object'],
            'mystery' => ['mystery'],
        ];
    }//end nullPreservationProvider()

    /*
        ====================================================================
     * Unknown schema type — falls through with string semantics
     * ==================================================================== */

    public function testUnknownSchemaTypePassesPlainStringThrough(): void
    {
        $this->assertSame(
            'hello',
            $this->converter->convertValue('hello', 'mystery'),
            'unknown type defaults to string semantics: passes plain text through'
        );
    }//end testUnknownSchemaTypePassesPlainStringThrough()

    public function testUnknownSchemaTypeDecodesArrayShapedStringForCompat(): void
    {
        $this->assertSame(
            [1],
            $this->converter->convertValue('[1]', 'mystery'),
            "unknown type defaults to string semantics: decodes array-shaped strings for backward compat"
        );
    }//end testUnknownSchemaTypeDecodesArrayShapedStringForCompat()

    public function testUnknownSchemaTypeCastsNumericInputToString(): void
    {
        $this->assertSame(
            '7',
            $this->converter->convertValue(7, 'mystery'),
            'unknown type defaults to string semantics: int → string'
        );
    }//end testUnknownSchemaTypeCastsNumericInputToString()

    /*
        ====================================================================
     * Empty schema type — same fallback as unknown
     * ==================================================================== */

    public function testEmptySchemaTypeUsesStringFallback(): void
    {
        $this->assertSame('hello', $this->converter->convertValue('hello', ''));
        $this->assertSame('5', $this->converter->convertValue(5, ''));
    }//end testEmptySchemaTypeUsesStringFallback()
}//end class
