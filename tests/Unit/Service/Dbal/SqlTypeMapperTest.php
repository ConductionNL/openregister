<?php

/**
 * Unit tests for SqlTypeMapper.
 *
 * Covers every row of the design D5 mapping table (DBAL Types::* → JSON-Schema
 * type/format), maxLength propagation for length-carrying strings, DECIMAL
 * precision/scale surfacing, the BIGINT precision note, the non-filterable flag
 * for binary/json/array types, and the logged string fallback for unknown types.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Dbal;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use OCA\OpenRegister\Service\Dbal\SqlTypeMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Test class for SqlTypeMapper.
 */
class SqlTypeMapperTest extends TestCase
{
    /**
     * Build a real DBAL Column of the given registered type.
     *
     * @param string               $typeName The DBAL type name (Types::*).
     * @param array<string, mixed> $options  Column options (length/precision/scale).
     *
     * @return Column The column.
     */
    private function column(string $typeName, array $options=[]): Column
    {
        return new Column('col', Type::getType($typeName), $options);
    }//end column()

    /**
     * Every row of the design D5 mapping table.
     *
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function mappingTable(): array
    {
        return [
            'string'               => [Types::STRING, 'string', null],
            'ascii_string'         => [Types::ASCII_STRING, 'string', null],
            'text'                 => [Types::TEXT, 'string', null],
            'guid'                 => [Types::GUID, 'string', 'uuid'],
            'integer'              => [Types::INTEGER, 'integer', null],
            'smallint'             => [Types::SMALLINT, 'integer', null],
            'bigint'               => [Types::BIGINT, 'integer', null],
            'decimal'              => [Types::DECIMAL, 'number', null],
            'float'                => [Types::FLOAT, 'number', null],
            'boolean'              => [Types::BOOLEAN, 'boolean', null],
            'date'                 => [Types::DATE_MUTABLE, 'string', 'date'],
            'date_immutable'       => [Types::DATE_IMMUTABLE, 'string', 'date'],
            'time'                 => [Types::TIME_MUTABLE, 'string', 'time'],
            'time_immutable'       => [Types::TIME_IMMUTABLE, 'string', 'time'],
            'datetime'             => [Types::DATETIME_MUTABLE, 'string', 'date-time'],
            'datetime_immutable'   => [Types::DATETIME_IMMUTABLE, 'string', 'date-time'],
            'datetimetz'           => [Types::DATETIMETZ_MUTABLE, 'string', 'date-time'],
            'datetimetz_immutable' => [Types::DATETIMETZ_IMMUTABLE, 'string', 'date-time'],
            'json'                 => [Types::JSON, 'object', null],
            'binary'               => [Types::BINARY, 'string', 'binary'],
            'blob'                 => [Types::BLOB, 'string', 'binary'],
            'simple_array'         => [Types::SIMPLE_ARRAY, 'array', null],
            'array'                => [Types::ARRAY, 'array', null],
        ];
    }//end mappingTable()

    /**
     * Each mapping-table row produces the expected JSON-Schema type/format.
     *
     * @param string      $dbalType The DBAL type name.
     * @param string      $jsonType The expected JSON-Schema type.
     * @param string|null $format   The expected format, or null.
     *
     * @return void
     *
     * @dataProvider mappingTable
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function testMappingTableRow(string $dbalType, string $jsonType, ?string $format): void
    {
        $mapper   = new SqlTypeMapper(logger: new NullLogger());
        $property = $mapper->mapColumn(column: $this->column(typeName: $dbalType));

        $this->assertSame($jsonType, $property['type']);
        if ($format === null) {
            $this->assertArrayNotHasKey('format', $property);
        } else {
            $this->assertSame($format, $property['format']);
        }
    }//end testMappingTableRow()

    /**
     * Column length maps onto maxLength for plain strings; TEXT never gets one.
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function testMaxLengthFromColumnLength(): void
    {
        $mapper = new SqlTypeMapper(logger: new NullLogger());

        $varchar = $mapper->mapColumn(column: $this->column(typeName: Types::STRING, options: ['length' => 255]));
        $this->assertSame(255, $varchar['maxLength']);

        $text = $mapper->mapColumn(column: $this->column(typeName: Types::TEXT, options: ['length' => 65535]));
        $this->assertArrayNotHasKey('maxLength', $text);
    }//end testMaxLengthFromColumnLength()

    /**
     * DECIMAL precision/scale is surfaced in the description.
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function testDecimalPrecisionScaleInDescription(): void
    {
        $mapper   = new SqlTypeMapper(logger: new NullLogger());
        $property = $mapper->mapColumn(
            column: $this->column(typeName: Types::DECIMAL, options: ['precision' => 10, 'scale' => 2])
        );

        $this->assertSame('number', $property['type']);
        $this->assertSame('DECIMAL(10,2)', $property['description']);
    }//end testDecimalPrecisionScaleInDescription()

    /**
     * BIGINT carries the JS-precision note.
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function testBigintPrecisionNote(): void
    {
        $mapper   = new SqlTypeMapper(logger: new NullLogger());
        $property = $mapper->mapColumn(column: $this->column(typeName: Types::BIGINT));

        $this->assertSame('integer', $property['type']);
        $this->assertStringContainsString('precision-lossy', $property['description']);
    }//end testBigintPrecisionNote()

    /**
     * Binary/JSON/array types are flagged non-filterable; scalars are filterable.
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function testNonFilterableFlag(): void
    {
        $mapper = new SqlTypeMapper(logger: new NullLogger());

        foreach ([Types::BINARY, Types::BLOB, Types::JSON, Types::SIMPLE_ARRAY, Types::ARRAY] as $typeName) {
            $property = $mapper->mapColumn(column: $this->column(typeName: $typeName));
            $this->assertFalse($property['x-filterable'], $typeName.' must be non-filterable');
        }

        $scalar = $mapper->mapColumn(column: $this->column(typeName: Types::STRING));
        $this->assertTrue($scalar['x-filterable']);
    }//end testNonFilterableFlag()

    /**
     * An unregistered/vendor type falls back to string with a logged warning.
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function testUnknownTypeFallsBackToStringWithWarning(): void
    {
        $logger = new class extends AbstractLogger {

            /**
             * Captured warning messages.
             *
             * @var array<int, string>
             */
            public array $warnings = [];

            /**
             * {@inheritDoc}
             *
             * @param mixed                $level   Log level.
             * @param string|\Stringable   $message Message.
             * @param array<string, mixed> $context Context.
             *
             * @return void
             */
            public function log($level, string|\Stringable $message, array $context=[]): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }//end log()
        };

        // DATEINTERVAL is registered in DBAL but absent from the D5 table.
        $mapper   = new SqlTypeMapper(logger: $logger);
        $property = $mapper->mapColumn(column: $this->column(typeName: Types::DATEINTERVAL));

        $this->assertSame('string', $property['type']);
        $this->assertFalse($property['x-filterable']);
        $this->assertNotEmpty($logger->warnings);
        $this->assertStringContainsString('unknown column type', $logger->warnings[0]);
    }//end testUnknownTypeFallsBackToStringWithWarning()
}//end class
