<?php

/**
 * Golden test: TablesColumnMapper's schema properties, column map and cell coercion.
 *
 * Pins, per Tables column type and subtype, the JSON-schema property a column maps
 * to and the value a cell is coerced to, plus slug collisions and unknown cells.
 * Recorded against the mapper before its long methods were split.
 * Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Service\ObjectSource\TablesColumnMapper;
use OCA\OpenRegister\Service\ObjectSource\TablesRowProjection;
use OCA\OpenRegister\Service\ObjectSource\TablesUuidDeriver;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * @covers \OCA\OpenRegister\Service\ObjectSource\TablesColumnMapper
 */
final class TablesColumnMapperGoldenTest extends TestCase {

	/**
	 * The recorded answers.
	 *
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/tables-column-mapper-golden.json';

	/**
	 * Every column, row and collision matches the recorded golden.
	 *
	 * @return void
	 */
	public function testMappingMatchesTheGolden(): void {
		$columns = [
			['id' => 1, 'title' => 'Plain', 'type' => 'text'],
			['id' => 2, 'title' => 'Link', 'type' => 'text', 'subtype' => 'link', 'mandatory' => true],
			['id' => 3, 'title' => 'Amount', 'type' => 'number', 'numberDecimals' => 2, 'numberMin' => '1.5', 'numberMax' => 10],
			['id' => 4, 'title' => 'Count', 'type' => 'number', 'numberDecimals' => 0, 'numberMin' => null],
			['id' => 5, 'title' => 'Progress', 'type' => 'number', 'subtype' => 'progress'],
			['id' => 6, 'title' => 'Stars', 'type' => 'number', 'subtype' => 'stars'],
			['id' => 7, 'title' => 'Ratio', 'type' => 'number'],
			['id' => 8, 'title' => 'When', 'type' => 'datetime'],
			['id' => 9, 'title' => 'Day', 'type' => 'datetime', 'subtype' => 'date'],
			['id' => 10, 'title' => 'Clock', 'type' => 'datetime', 'subtype' => 'time'],
			['id' => 11, 'title' => 'Done', 'type' => 'selection', 'subtype' => 'check'],
			['id' => 12, 'title' => 'Tags', 'type' => 'selection', 'subtype' => 'multi', 'selectionOptions' => [1, 'b']],
			['id' => 13, 'title' => 'Pick', 'type' => 'selection', 'selectionOptions' => ['x', 'y']],
			['id' => 14, 'title' => 'Pick none', 'type' => 'selection'],
			['id' => 15, 'title' => 'Who', 'type' => 'usergroup'],
			['id' => 16, 'title' => 'Link to', 'type' => 'relation', 'relationTargetTableId' => 77],
			['id' => 17, 'title' => 'Link gone', 'type' => 'relation', 'relationTargetTableId' => 78],
			['id' => 18, 'title' => 'Link none', 'type' => 'relation'],
			['id' => 19, 'title' => 'Odd', 'type' => 'mystery', 'mandatory' => 'yes'],
			['id' => 20, 'title' => 'Plain', 'type' => 'text'],
			['id' => 21, 'title' => '***', 'type' => 'text'],
			['id' => 22, 'technicalName' => ' Tech Name ', 'title' => 'ignored', 'type' => 'text'],
			['id' => 0, 'title' => 'No id', 'type' => 'text'],
		];
		$cells = [
			[1, 'hello'], [2, ['url' => 'x']], [3, '2.50'], [4, '3.9'], [5, '42'], [6, 4.7], [7, '1e2'],
			[8, 20260101], [9, null], [11, 'false'], [11, 'true'], [12, 'solo'], [13, 7], [15, 'alice'],
			[16, '12'], [17, 13], [18, 'x9'], [19, 3.25], [21, false], [99, 'drift'],
		];

		$logger = new TablesGoldenLogger();
		$mapper = new TablesColumnMapper(uuidDeriver: new TablesUuidDeriver(), logger: $logger);

		$rows = [];
		foreach ($cells as $index => [$columnId, $value]) {
			$rows[$index . ':' . $columnId] = $mapper->projectRow(
				row: ['id' => $index, 'cells' => [['columnId' => $columnId, 'value' => $value]]],
				projection: new TablesRowProjection(
					columns: $columns,
					columnMap: $mapper->buildColumnMap(columns: $columns),
					targetSchemaExists: static fn (int $tableId): bool => $tableId === 77
				)
			);
		}

		$actual = [
			'columnMap'  => $mapper->buildColumnMap(columns: $columns),
			'schema'     => $mapper->buildSchemaProperties(columns: $columns),
			'rows'       => $rows,
			'noCells'    => $mapper->projectRow(row: ['id' => 'x'], projection: new TablesRowProjection(columns: $columns, columnMap: [], targetSchemaExists: static fn (): bool => false)),
			'logs'       => $logger->lines,
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$recorded = json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), true);
		$this->assertSame($expected, $recorded);
	}//end testMappingMatchesTheGolden()
}//end class

/**
 * Records `level: message` lines.
 */
final class TablesGoldenLogger extends AbstractLogger {

	/**
	 * The logged `level: message` lines.
	 *
	 * @var array<int, string>
	 */
	public array $lines = [];

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed               $level   The level.
	 * @param string|\Stringable $message The message.
	 * @param array<mixed>        $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|\Stringable $message, array $context = []): void {
		$this->lines[] = $level . ': ' . $message;
	}
}
