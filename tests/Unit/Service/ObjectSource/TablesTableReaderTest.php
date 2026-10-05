<?php

/**
 * Unit tests for TablesTableReader.
 *
 * The Tables app's `OCA\Tables\Service\*` classes are not loadable under the CI
 * runner, so these tests cover the fail-closed contract that is observable
 * without Tables: availability is false, and every read degrades to an empty /
 * null result rather than a fatal. The live extraction of real Tables entities is
 * verified once the Tables app is installed.
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

use OCA\OpenRegister\Service\ObjectSource\TablesTableReader;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Test class for TablesTableReader.
 */
class TablesTableReaderTest extends TestCase {

	/**
	 * Build a reader with a configurable app-enabled state.
	 *
	 * @param bool $appThere Whether the Tables app reports enabled.
	 *
	 * @return TablesTableReader The reader under test.
	 */
	private function reader(bool $appThere = true): TablesTableReader {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn($appThere);
		$container = $this->createMock(ContainerInterface::class);

		return new TablesTableReader($appManager, $container, new NullLogger());
	}//end reader()

	/**
	 * isAvailable() is false when the Tables service classes are not loadable,
	 * even when the app reports enabled (the CI runner has no Tables app).
	 *
	 * @return void
	 */
	public function testIsAvailableFalseWithoutTablesClasses(): void {
		if (class_exists('OCA\\Tables\\Service\\RowService') === true) {
			$this->markTestSkipped('Tables app is present; availability is covered by live-verify.');
		}

		$this->assertFalse($this->reader(true)->isAvailable());
		$this->assertFalse($this->reader(false)->isAvailable());
	}//end testIsAvailableFalseWithoutTablesClasses()

	/**
	 * Every read fails closed to empty/null/0 when Tables is absent.
	 *
	 * @return void
	 */
	public function testReadsFailClosed(): void {
		if (class_exists('OCA\\Tables\\Service\\RowService') === true) {
			$this->markTestSkipped('Tables app is present; mapping is covered by live-verify.');
		}

		$reader = $this->reader(true);

		$this->assertSame([], $reader->listTables(userId: 'alice'));
		$this->assertSame([], $reader->listColumns(tableId: 5, userId: 'alice'));
		$this->assertSame([], $reader->findRowsByTable(tableId: 5, userId: 'alice'));
		$this->assertSame([], $reader->findRowsByView(viewId: 9, userId: 'alice'));
		$this->assertSame([], $reader->collectTableDescriptors(userIds: ['alice']));
		$this->assertNull($reader->findRow(rowId: 1, tableId: 5, userId: 'alice'));
		$this->assertSame(0, $reader->countRows(id: 5, userId: 'alice'));
	}//end testReadsFailClosed()

	/**
	 * Declare minimal Tables services, so findRow() can be driven without the
	 * Tables app. Only called from tests that run in a separate process, so the
	 * fakes never leak into the tests above, which need Tables to be absent.
	 *
	 * The fake RowService behaves like the real one where it matters here: it
	 * takes only the row id and serves a row of ANY table, whoever asks.
	 *
	 * @param array<int, int> $rowTables Row id => the table that row belongs to.
	 * @param array<string, array<int, int>> $readable User id => table ids that user may read.
	 *
	 * @return TablesTableReader The reader, wired to the fakes.
	 */
	private function readerOverFakeTables(array $rowTables, array $readable): TablesTableReader {
		eval(
			<<<'PHP'
			namespace OCA\Tables\Service;

			class RowService {
				public static array $rowTables = [];
				public static array $found = [];

				public function find(int $id): object {
					self::$found[] = $id;
					$tableId = self::$rowTables[$id];

					return new class($id, $tableId) {
						public function __construct(private int $id, private int $tableId) {
						}

						public function getId(): int {
							return $this->id;
						}

						public function getTableId(): int {
							return $this->tableId;
						}

						public function getData(): array {
							return [['columnId' => 1, 'value' => 'Swing']];
						}
					};
				}
			}

			class PermissionsService {
				public static array $readable = [];

				public function canReadRowsByElementId(int $elementId, string $nodeType, ?string $userId = null): bool {
					return $nodeType === 'table' && in_array($elementId, self::$readable[$userId] ?? [], true);
				}
			}
			PHP
		);

		\OCA\Tables\Service\RowService::$rowTables = $rowTables;
		\OCA\Tables\Service\PermissionsService::$readable = $readable;

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $class): object => new $class()
		);

		return new TablesTableReader($appManager, $container, new NullLogger());
	}//end readerOverFakeTables()

	/**
	 * A row is refused when the user asking may not read the bound table, even
	 * though Tables' own session-scoped find() would have served it.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testFindRowRefusesAUserWhoMayNotReadTheTable(): void {
		$reader = $this->readerOverFakeTables(rowTables: [42 => 5], readable: ['admin' => [5], 'bob' => []]);

		$this->assertNull($reader->findRow(rowId: 42, tableId: 5, userId: 'bob'));
		$this->assertSame([], \OCA\Tables\Service\RowService::$found, 'the row must not even be read for bob');
	}//end testFindRowRefusesAUserWhoMayNotReadTheTable()

	/**
	 * A row of another table is not an object of the schema bound to this one.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testFindRowRefusesARowOfAnotherTable(): void {
		$reader = $this->readerOverFakeTables(rowTables: [42 => 6], readable: ['alice' => [5, 6]]);

		$this->assertNull($reader->findRow(rowId: 42, tableId: 5, userId: 'alice'));
	}//end testFindRowRefusesARowOfAnotherTable()

	/**
	 * A row of the bound table that the asking user may read comes back.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testFindRowReturnsAReadableRowOfTheBoundTable(): void {
		$reader = $this->readerOverFakeTables(rowTables: [42 => 5], readable: ['alice' => [5]]);

		$row = $reader->findRow(rowId: 42, tableId: 5, userId: 'alice');

		$this->assertNotNull($row);
		$this->assertSame(42, $row['id']);
		$this->assertSame([['columnId' => 1, 'value' => 'Swing']], $row['cells']);
	}//end testFindRowReturnsAReadableRowOfTheBoundTable()
}//end class
