<?php

/**
 * How Tables columns map to schema properties and cells to object values,
 * per column type and subtype, and how naming collisions and drift resolve.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Service\ObjectSource\TablesColumnMapper;
use OCA\OpenRegister\Service\ObjectSource\TablesRowProjection;
use OCA\OpenRegister\Service\ObjectSource\TablesUuidDeriver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Branch tests for {@see TablesColumnMapper}.
 */
class TablesColumnMapperBehaviourTest extends TestCase {

	/**
	 * The logger, mocked to observe drift and collision warnings.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The mapper under test.
	 *
	 * @var TablesColumnMapper
	 */
	private TablesColumnMapper $mapper;

	/**
	 * Build the mapper over the real uuid deriver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->mapper = new TablesColumnMapper(uuidDeriver: new TablesUuidDeriver(), logger: $this->logger);
	}//end setUp()

	/**
	 * A column without an id is left out; a title-less column is named after
	 * its id; a collision gets the column id appended and is logged.
	 *
	 * @return void
	 */
	public function testColumnNamingSkipsIdlessFallsBackAndDisambiguates(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('column 4 slug "name" collides with column 3; using "name_4"'));

		$map = $this->mapper->buildColumnMap(
			columns: [
				['title' => 'No id'],
				['id' => 2, 'title' => '!!!'],
				['id' => 3, 'technicalName' => ' Name '],
				['id' => 4, 'title' => 'Name'],
			]
		);

		$this->assertSame([2 => 'column_2', 3 => 'name', 4 => 'name_4'], $map);
	}//end testColumnNamingSkipsIdlessFallsBackAndDisambiguates()

	/**
	 * Schema properties skip an unmapped column and list mandatory ones as required.
	 *
	 * @return void
	 */
	public function testSchemaPropertiesListMandatoryColumnsAsRequired(): void {
		$schema = $this->mapper->buildSchemaProperties(
			columns: [
				['title' => 'ghost', 'mandatory' => true],
				['id' => 1, 'title' => 'Title', 'mandatory' => true],
				['id' => 2, 'title' => 'Note', 'mandatory' => 'yes'],
			]
		);

		$this->assertSame(['title', 'note'], array_keys($schema['properties']));
		$this->assertSame(['title'], $schema['required']);
	}//end testSchemaPropertiesListMandatoryColumnsAsRequired()

	/**
	 * Every column type and subtype maps to its schema fragment.
	 *
	 * @return void
	 */
	public function testEveryColumnTypeMapsToItsProperty(): void {
		$cases = [
			[['type' => 'number', 'subtype' => 'progress'], ['type' => 'integer', 'minimum' => 0, 'maximum' => 100]],
			[['type' => 'number', 'subtype' => 'stars'], ['type' => 'integer', 'minimum' => 0, 'maximum' => 5]],
			[['type' => 'number', 'numberDecimals' => 0, 'numberMin' => '1', 'numberMax' => 9], ['type' => 'integer', 'minimum' => 1, 'maximum' => 9]],
			[['type' => 'number', 'numberDecimals' => 2, 'numberMin' => null, 'numberMax' => null], ['type' => 'number']],
			[['type' => 'number', 'numberDecimals' => null], ['type' => 'number']],
			[['type' => 'datetime', 'subtype' => 'date'], ['type' => 'string', 'format' => 'date']],
			[['type' => 'datetime', 'subtype' => 'time'], ['type' => 'string', 'format' => 'time']],
			[['type' => 'datetime'], ['type' => 'string', 'format' => 'date-time']],
			[['type' => 'selection', 'subtype' => 'check'], ['type' => 'boolean']],
			[['type' => 'selection', 'subtype' => 'multi', 'selectionOptions' => [1, 'b']], ['type' => 'array', 'items' => ['enum' => ['1', 'b']]]],
			[['type' => 'selection', 'selectionOptions' => ['a']], ['type' => 'string', 'enum' => ['a']]],
			[['type' => 'selection'], ['type' => 'string']],
			[['type' => 'usergroup'], ['type' => 'array', 'items' => ['type' => 'object']]],
			[['type' => 'relation'], ['type' => 'string', 'format' => 'uuid']],
			[['type' => 'text', 'subtype' => 'link'], ['type' => 'string', 'format' => 'uri']],
			[[], ['type' => 'string']],
		];
		foreach ($cases as [$column, $expected]) {
			$this->assertSame($expected, $this->mapper->columnToProperty(column: $column), json_encode($column));
		}
	}//end testEveryColumnTypeMapsToItsProperty()

	/**
	 * Every cell type coerces to its projected value; a cell of an unknown
	 * column is skipped and logged as drift.
	 *
	 * @return void
	 */
	public function testCellsCoerceByTypeAndDriftIsSkipped(): void {
		$columns = [
			['id' => 1, 'title' => 'progress', 'type' => 'number', 'subtype' => 'progress'],
			['id' => 2, 'title' => 'amount', 'type' => 'number'],
			['id' => 3, 'title' => 'count', 'type' => 'number', 'numberDecimals' => 0],
			['id' => 4, 'title' => 'done', 'type' => 'selection', 'subtype' => 'check'],
			['id' => 5, 'title' => 'tags', 'type' => 'selection', 'subtype' => 'multi'],
			['id' => 6, 'title' => 'pick', 'type' => 'selection'],
			['id' => 7, 'title' => 'people', 'type' => 'usergroup'],
			['id' => 8, 'title' => 'text', 'type' => 'text'],
			['id' => 9, 'title' => 'rich', 'type' => 'text'],
			['id' => 10, 'title' => 'empty', 'type' => 'text'],
		];
		$map = $this->mapper->buildColumnMap(columns: $columns);
		$this->logger->expects($this->once())
			->method('warning')
			->with('[ObjectSource:tables] row 77 references unknown column 99 — skipped (drift)');

		$data = $this->mapper->projectRow(
			row: [
				'id' => 77,
				'cells' => [
					['columnId' => 1, 'value' => '42.7'],
					['columnId' => 2, 'value' => '3.5'],
					['columnId' => 3, 'value' => '8.9'],
					['columnId' => 4, 'value' => 'true'],
					['columnId' => 5, 'value' => 'x'],
					['columnId' => 6, 'value' => 12],
					['columnId' => 7, 'value' => 'u1'],
					['columnId' => 8, 'value' => 15],
					['columnId' => 9, 'value' => ['ops' => 1]],
					['columnId' => 10],
					['columnId' => 99, 'value' => 'drift'],
				],
			],
			projection: new TablesRowProjection(
				columns: $columns,
				columnMap: $map,
				targetSchemaExists: static fn (int $tableId): bool => false
			)
		);

		$this->assertSame(
			['progress' => 42, 'amount' => 3.5, 'count' => 8, 'done' => true, 'tags' => ['x'], 'pick' => '12', 'people' => ['u1'], 'text' => '15', 'rich' => ['ops' => 1], 'empty' => null],
			$data
		);
	}//end testCellsCoerceByTypeAndDriftIsSkipped()

	/**
	 * A relation to a seeded table projects the derived uuid; without one it
	 * falls back to the raw row id and says so.
	 *
	 * @return void
	 */
	public function testRelationCellsDeriveAUuidOnlyForASeededTarget(): void {
		$columns = [
			['id' => 1, 'title' => 'seeded', 'type' => 'relation', 'relationTargetTableId' => 5],
			['id' => 2, 'title' => 'unseeded', 'type' => 'relation', 'relationTargetTableId' => 6],
			['id' => 3, 'title' => 'untargeted', 'type' => 'relation'],
		];
		$map = $this->mapper->buildColumnMap(columns: $columns);
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$asked = [];

		$data = $this->mapper->projectRow(
			row: ['cells' => [['columnId' => 1, 'value' => '9'], ['columnId' => 2, 'value' => 9], ['columnId' => 3, 'value' => 4]]],
			projection: new TablesRowProjection(
				columns: $columns,
				columnMap: $map,
				targetSchemaExists: static function (int $tableId) use (&$asked): bool {
					$asked[] = $tableId;

					return $tableId === 5;
				}
			)
		);

		$this->assertSame((new TablesUuidDeriver())->deriveObjectUuid(tableId: 5, rowId: 9), $data['seeded']);
		$this->assertSame(9, $data['unseeded']);
		$this->assertSame(4, $data['untargeted']);
		$this->assertSame([5, 6], $asked, 'a column without a target table never asks');
		$this->assertCount(2, $warnings);
		$this->assertStringContainsString('relation column 2 target table 6 has no seeded schema — falling back to raw rowId 9', $warnings[0]);
	}//end testRelationCellsDeriveAUuidOnlyForASeededTarget()
}//end class
