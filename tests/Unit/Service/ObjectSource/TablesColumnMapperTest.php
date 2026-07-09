<?php

/**
 * Unit tests for TablesColumnMapper.
 *
 * Covers the pure Tables-column ↔ virtual-schema projection: the column-type →
 * JSON-schema mapping (design D5), the `columnId → property name` resolution with
 * collision disambiguation, row projection with column-drift handling, and the
 * relation → UUID derivation with the missing-target fallback (design D9). No
 * Tables app is required — everything runs on plain-array descriptors.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
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
 * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Service\ObjectSource\TablesColumnMapper;
use OCA\OpenRegister\Service\ObjectSource\TablesUuidDeriver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test class for TablesColumnMapper.
 */
class TablesColumnMapperTest extends TestCase
{

    /**
     * The mapper under test.
     *
     * @var TablesColumnMapper
     */
    private TablesColumnMapper $mapper;

    /**
     * The deriver used to compute expected relation uuids.
     *
     * @var TablesUuidDeriver
     */
    private TablesUuidDeriver $deriver;

    /**
     * Build the mapper before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->deriver = new TablesUuidDeriver();
        $this->mapper  = new TablesColumnMapper($this->deriver, new NullLogger());
    }//end setUp()

    /**
     * The full column-type → JSON-schema mapping (design D5).
     *
     * @return void
     */
    public function testColumnTypeMapping(): void
    {
        $this->assertSame(['type' => 'string'], $this->mapper->columnToProperty(['type' => 'text', 'subtype' => 'line']));
        $this->assertSame(['type' => 'string', 'format' => 'uri'], $this->mapper->columnToProperty(['type' => 'text', 'subtype' => 'link']));
        $this->assertSame(['type' => 'integer', 'minimum' => 0, 'maximum' => 100], $this->mapper->columnToProperty(['type' => 'number', 'subtype' => 'progress']));
        $this->assertSame(['type' => 'integer', 'minimum' => 0, 'maximum' => 5], $this->mapper->columnToProperty(['type' => 'number', 'subtype' => 'stars']));
        $this->assertSame(['type' => 'string', 'format' => 'date'], $this->mapper->columnToProperty(['type' => 'datetime', 'subtype' => 'date']));
        $this->assertSame(['type' => 'string', 'format' => 'time'], $this->mapper->columnToProperty(['type' => 'datetime', 'subtype' => 'time']));
        $this->assertSame(['type' => 'string', 'format' => 'date-time'], $this->mapper->columnToProperty(['type' => 'datetime']));
        $this->assertSame(['type' => 'boolean'], $this->mapper->columnToProperty(['type' => 'selection', 'subtype' => 'check']));
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'object']], $this->mapper->columnToProperty(['type' => 'usergroup']));
        $this->assertSame(['type' => 'string', 'format' => 'uuid'], $this->mapper->columnToProperty(['type' => 'relation']));

        $enum = $this->mapper->columnToProperty(['type' => 'selection', 'selectionOptions' => ['open', 'done']]);
        $this->assertSame(['type' => 'string', 'enum' => ['open', 'done']], $enum);

        $multi = $this->mapper->columnToProperty(['type' => 'selection', 'subtype' => 'multi', 'selectionOptions' => ['a', 'b']]);
        $this->assertSame(['type' => 'array', 'items' => ['enum' => ['a', 'b']]], $multi);
    }//end testColumnTypeMapping()

    /**
     * A number column with integral decimals maps to integer with bounds.
     *
     * @return void
     */
    public function testNumberBoundsAndIntegral(): void
    {
        $property = $this->mapper->columnToProperty(['type' => 'number', 'numberDecimals' => 0, 'numberMin' => 1, 'numberMax' => 9]);
        $this->assertSame('integer', $property['type']);
        $this->assertSame(1, $property['minimum']);
        $this->assertSame(9, $property['maximum']);
    }//end testNumberBoundsAndIntegral()

    /**
     * buildSchemaProperties() emits properties and a required[] from mandatory.
     *
     * @return void
     */
    public function testBuildSchemaPropertiesAndRequired(): void
    {
        $columns = [
            ['id' => 101, 'title' => 'Toestel', 'technicalName' => 'toestel', 'type' => 'text', 'mandatory' => true],
            ['id' => 105, 'title' => 'Score', 'type' => 'number', 'subtype' => 'stars', 'mandatory' => false],
        ];

        $shape = $this->mapper->buildSchemaProperties(columns: $columns);

        $this->assertSame(['type' => 'string'], $shape['properties']['toestel']);
        $this->assertSame(['type' => 'integer', 'minimum' => 0, 'maximum' => 5], $shape['properties']['score']);
        $this->assertSame(['toestel'], $shape['required']);
    }//end testBuildSchemaPropertiesAndRequired()

    /**
     * technicalName is preferred; a missing one falls back to slug(title).
     *
     * @return void
     */
    public function testColumnMapNameResolution(): void
    {
        $map = $this->mapper->buildColumnMap(
            [
                ['id' => 1, 'title' => 'Full Name', 'technicalName' => 'full_name'],
                ['id' => 2, 'title' => 'Inspectie Datum'],
            ]
        );

        $this->assertSame('full_name', $map[1]);
        $this->assertSame('inspectie_datum', $map[2]);
    }//end testColumnMapNameResolution()

    /**
     * Two columns slugging to the same name are disambiguated by columnId.
     *
     * @return void
     */
    public function testColumnMapCollisionDisambiguated(): void
    {
        $map = $this->mapper->buildColumnMap(
            [
                ['id' => 1, 'title' => 'Status'],
                ['id' => 2, 'title' => 'status'],
            ]
        );

        $this->assertSame('status', $map[1]);
        $this->assertSame('status_2', $map[2]);
    }//end testColumnMapCollisionDisambiguated()

    /**
     * Row projection maps cells to properties and coerces per type.
     *
     * @return void
     */
    public function testProjectRowCoercesValues(): void
    {
        $columns = [
            ['id' => 1, 'title' => 'Name', 'technicalName' => 'name', 'type' => 'text'],
            ['id' => 2, 'title' => 'Score', 'technicalName' => 'score', 'type' => 'number', 'subtype' => 'stars'],
            ['id' => 3, 'title' => 'Done', 'technicalName' => 'done', 'type' => 'selection', 'subtype' => 'check'],
        ];
        $row = [
            'id'    => 9,
            'cells' => [
                ['columnId' => 1, 'value' => 'Swing'],
                ['columnId' => 2, 'value' => '4'],
                ['columnId' => 3, 'value' => 1],
            ],
        ];

        $map  = $this->mapper->buildColumnMap(columns: $columns);
        $data = $this->mapper->projectRow(row: $row, columns: $columns, columnMap: $map, targetSchemaExists: static fn(int $t) => false);

        $this->assertSame('Swing', $data['name']);
        $this->assertSame(4, $data['score']);
        $this->assertTrue($data['done']);
    }//end testProjectRowCoercesValues()

    /**
     * Check cells stored as the string 'false' coerce to boolean false
     * (live Tables stores check values as 'true'/'false' strings; a naive
     * bool cast turns 'false' into true).
     *
     * @return void
     */
    public function testProjectRowCoercesStringFalseCheckCell(): void
    {
        $columns = [['id' => 3, 'title' => 'Done', 'technicalName' => 'done', 'type' => 'selection', 'subtype' => 'check']];
        $row     = [
            'id'    => 9,
            'cells' => [['columnId' => 3, 'value' => 'false']],
        ];

        $map  = $this->mapper->buildColumnMap(columns: $columns);
        $data = $this->mapper->projectRow(row: $row, columns: $columns, columnMap: $map, targetSchemaExists: static fn(int $t) => false);

        $this->assertFalse($data['done']);
    }//end testProjectRowCoercesStringFalseCheckCell()

    /**
     * A cell referencing a dropped column is skipped (column drift).
     *
     * @return void
     */
    public function testProjectRowSkipsUnknownColumn(): void
    {
        $columns = [['id' => 1, 'title' => 'Name', 'technicalName' => 'name', 'type' => 'text']];
        $row     = [
            'id'    => 9,
            'cells' => [
                ['columnId' => 1, 'value' => 'Swing'],
                ['columnId' => 99, 'value' => 'orphan'],
            ],
        ];

        $map  = $this->mapper->buildColumnMap(columns: $columns);
        $data = $this->mapper->projectRow(row: $row, columns: $columns, columnMap: $map, targetSchemaExists: static fn(int $t) => false);

        $this->assertSame(['name' => 'Swing'], $data);
    }//end testProjectRowSkipsUnknownColumn()

    /**
     * A relation cell maps to the derived uuid when the target schema exists.
     *
     * @return void
     */
    public function testRelationMapsToDerivedUuid(): void
    {
        $columns = [['id' => 8, 'title' => 'Contract', 'technicalName' => 'contract', 'type' => 'relation', 'relationTargetTableId' => 12]];
        $row     = ['id' => 9, 'cells' => [['columnId' => 8, 'value' => 34]]];

        $map  = $this->mapper->buildColumnMap(columns: $columns);
        $data = $this->mapper->projectRow(row: $row, columns: $columns, columnMap: $map, targetSchemaExists: static fn(int $t) => $t === 12);

        $this->assertSame($this->deriver->deriveObjectUuid(tableId: 12, rowId: 34), $data['contract']);
    }//end testRelationMapsToDerivedUuid()

    /**
     * A relation cell falls back to the raw rowId when no target schema exists.
     *
     * @return void
     */
    public function testRelationFallsBackToRawRowId(): void
    {
        $columns = [['id' => 8, 'title' => 'Contract', 'technicalName' => 'contract', 'type' => 'relation', 'relationTargetTableId' => 12]];
        $row     = ['id' => 9, 'cells' => [['columnId' => 8, 'value' => 34]]];

        $map  = $this->mapper->buildColumnMap(columns: $columns);
        $data = $this->mapper->projectRow(row: $row, columns: $columns, columnMap: $map, targetSchemaExists: static fn(int $t) => false);

        $this->assertSame(34, $data['contract']);
    }//end testRelationFallsBackToRawRowId()
}//end class
