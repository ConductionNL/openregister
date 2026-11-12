<?php

/**
 * Golden test of what TablesTableReader asks Tables and what it hands back.
 *
 * Every test runs in a separate process, because the fakes make the Tables service
 * classes loadable and TablesTableReaderTest needs them absent.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectSource\TablesReadScope;
use OCA\OpenRegister\Service\ObjectSource\TablesTableReader;
use OCA\Tables\Service\ColumnService;
use OCA\Tables\Service\FakeEntity;
use OCA\Tables\Service\PermissionsService;
use OCA\Tables\Service\RowService;
use OCA\Tables\Service\TableService;
use OCA\Tables\Service\ViewService;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * @covers \OCA\OpenRegister\Service\ObjectSource\TablesTableReader
 * @covers \OCA\OpenRegister\Service\ObjectSource\TablesEntityDescriptors
 * @uses   \OCA\OpenRegister\Service\ObjectSource\ContainerServiceLookup
 * @uses   \OCA\OpenRegister\Service\ObjectSource\TablesReadScope
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class TablesTableReaderGoldenTest extends TestCase {

	/**
	 * Warnings the reader logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Build a reader over the fakes; a service in $broken cannot be resolved.
	 *
	 * @param array<int, string> $broken Service classes the container refuses.
	 *
	 * @return TablesTableReader The reader.
	 */
	private function reader(array $broken = []): TablesTableReader {
		require_once __DIR__ . '/fixtures/tables-service-fakes.php';

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($broken): object {
				if (in_array($class, $broken, true) === true) {
					throw new RuntimeException('unavailable');
				}

				return new $class();
			}
		);
		$warnings = &$this->warnings;
		$logger = new class($warnings) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array<int, string> $sink Where messages go.
			 */
			public function __construct(private array &$sink) {
			}

			/**
			 * Record a message.
			 *
			 * @param mixed $level The level.
			 * @param string|Stringable $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->sink[] = $level . ' ' . $message;
			}//end log()
		};

		return new TablesTableReader(app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $appManager, container: $container, logger: $logger));
	}//end reader()

	/**
	 * A full Row2-shaped entity.
	 *
	 * @param int $id The row id.
	 *
	 * @return object The entity.
	 */
	private function rowEntity(int $id): object {
		return new FakeEntity(
			[
				'getId' => $id,
				'getCreatedBy' => 'alice',
				'getCreatedAt' => new DateTimeImmutable('2026-01-02T03:04:05+00:00'),
				'getLastEditBy' => 7,
				'getLastEditAt' => '2026-02-03 10:00:00',
				'getData' => [
					['columnId' => '1', 'value' => 'a'],
					['value' => 'no column'],
					['columnId' => 2],
					new FakeEntity(['getColumnId' => 3, 'getValue' => 'b']),
					new FakeEntity(['getValue' => 'no column id']),
					new FakeEntity(['getColumnId' => 4]),
					'scalar cell',
				],
			]
		);
	}//end rowEntity()

	/**
	 * The descriptor rowEntity() maps to.
	 *
	 * @param int $id The row id.
	 *
	 * @return array<string, mixed> The descriptor.
	 */
	private function rowDescriptor(int $id): array {
		return [
			'id' => $id,
			'createdBy' => 'alice',
			'createdAt' => '2026-01-02T03:04:05+00:00',
			'lastEditBy' => '7',
			'lastEditAt' => '2026-02-03 10:00:00',
			'cells' => [
				['columnId' => 1, 'value' => 'a'],
				['columnId' => 2, 'value' => null],
				['columnId' => 3, 'value' => 'b'],
				['columnId' => 4, 'value' => null],
			],
		];
	}//end rowDescriptor()

	/**
	 * Read a page of table rows through the reader's table read.
	 *
	 * @param TablesTableReader $reader The reader.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function tableRows(TablesTableReader $reader): array {
		return $reader->findRows(scope: (new TablesReadScope(5, 'alice'))->withPage(limit: 10, offset: 20));
	}//end tableRows()

	/**
	 * Read a page of view rows through the reader's view read.
	 *
	 * @param TablesTableReader $reader The reader.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function viewRows(TablesTableReader $reader): array {
		return $reader->findRows(scope: (new TablesReadScope(5, 'alice'))->withView(viewId: 9)->withPage(limit: 10, offset: 20));
	}//end viewRows()

	/**
	 * Count the rows of the table.
	 *
	 * @param TablesTableReader $reader The reader.
	 *
	 * @return int The count.
	 */
	private function countTable(TablesTableReader $reader): int {
		return $reader->countRows(scope: new TablesReadScope(5, 'alice'));
	}//end countTable()

	/**
	 * Count the rows of the view.
	 *
	 * @param TablesTableReader $reader The reader.
	 *
	 * @return int The count.
	 */
	private function countView(TablesTableReader $reader): int {
		return $reader->countRows(scope: (new TablesReadScope(5, 'alice'))->withView(viewId: 9));
	}//end countView()

	/**
	 * Read row 42 of table 5 as alice.
	 *
	 * @param TablesTableReader $reader The reader.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	private function boundRow(TablesTableReader $reader): ?array {
		return $reader->findRow(rowId: 42, scope: new TablesReadScope(5, 'alice'));
	}//end boundRow()

	/**
	 * Tables are listed without creating the tutorial table, and rows that are not
	 * entities or have no id are skipped.
	 *
	 * @return void
	 */
	public function testListTables(): void {
		$reader = $this->reader();
		TableService::$replies['findAll'] = [
			new FakeEntity(['getId' => '5', 'getTitle' => 'Bands']),
			'not an entity',
			new FakeEntity(['getTitle' => 'No id']),
			new FakeEntity(['getId' => [1], 'getTitle' => 'Array id']),
			new FakeEntity(['getId' => 6]),
		];

		$this->assertSame(
			[['id' => 5, 'title' => 'Bands'], ['id' => 6, 'title' => '']],
			$reader->listTables(userId: 'alice')
		);
		$this->assertSame([['findAll', 'alice', false, false, false]], TableService::$calls);
	}//end testListTables()

	/**
	 * A failing listing logs and degrades to empty.
	 *
	 * @return void
	 */
	public function testListTablesFailureIsEmpty(): void {
		$reader = $this->reader();
		TableService::$replies['findAll'] = new RuntimeException('boom');

		$this->assertSame([], $reader->listTables(userId: 'alice'));
		$this->assertSame(['warning [ObjectSource:tables] could not list tables: boom'], $this->warnings);
	}//end testListTablesFailureIsEmpty()

	/**
	 * An unresolvable service logs its own warning and degrades to empty.
	 *
	 * @return void
	 */
	public function testUnresolvableServicesAreEmpty(): void {
		$reader = $this->reader([TableService::class, ColumnService::class, RowService::class]);

		$this->assertSame([], $reader->listTables(userId: 'alice'));
		$this->assertSame([], $reader->listColumns(tableId: 5, userId: 'alice'));
		$this->assertSame([], $this->tableRows($reader));
		$this->assertSame(0, $this->countTable($reader));
		$this->assertCount(4, $this->warnings);
	}//end testUnresolvableServicesAreEmpty()

	/**
	 * Descriptors are deduplicated by table id, columns come from the first user
	 * that sees the table.
	 *
	 * @return void
	 */
	public function testCollectTableDescriptors(): void {
		$reader = $this->reader();
		TableService::$replies['findAll'] = [new FakeEntity(['getId' => 5, 'getTitle' => 'Bands'])];
		ColumnService::$replies['findAllByTable'] = [];

		$this->assertSame(
			[['id' => 5, 'title' => 'Bands', 'columns' => []]],
			$reader->collectTableDescriptors(userIds: ['alice', 'bob'])
		);
		$this->assertSame([['findAllByTable', 5, 'alice']], ColumnService::$calls);
		$this->assertCount(2, TableService::$calls);
	}//end testCollectTableDescriptors()

	/**
	 * A column maps to its plain descriptor, tolerant of option shapes and missing getters.
	 *
	 * @return void
	 */
	public function testListColumns(): void {
		$reader = $this->reader();
		ColumnService::$replies['findAllByTable'] = [
			new FakeEntity(
				[
					'getId' => '1',
					'getTitle' => 'Name',
					'getTechnicalName' => 'name',
					'getType' => 'selection',
					'getSubtype' => 'multi',
					'getMandatory' => 1,
					'getNumberMin' => '1.5',
					'getNumberMax' => 9,
					'getNumberDecimals' => '2',
					'getSelectionOptions' => [['label' => 'A'], ['value' => 'B'], ['other' => 'x'], 'C', ''],
					'getRelationTableId' => '6',
				]
			),
			'not an entity',
			new FakeEntity([]),
		];

		$this->assertSame(
			[
				[
					'id' => 1,
					'title' => 'Name',
					'technicalName' => 'name',
					'type' => 'selection',
					'subtype' => 'multi',
					'mandatory' => true,
					'numberMin' => 1.5,
					'numberMax' => 9.0,
					'numberDecimals' => 2,
					'selectionOptions' => ['A', 'B', 'C'],
					'relationTargetTableId' => 6,
				],
				[
					'id' => 0,
					'title' => '',
					'technicalName' => null,
					'type' => '',
					'subtype' => null,
					'mandatory' => false,
					'numberMin' => null,
					'numberMax' => null,
					'numberDecimals' => null,
					'selectionOptions' => [],
					'relationTargetTableId' => null,
				],
			],
			$reader->listColumns(tableId: 5, userId: 'alice')
		);
		$this->assertSame([['findAllByTable', 5, 'alice']], ColumnService::$calls);
	}//end testListColumns()

	/**
	 * A failing column read logs and degrades to empty.
	 *
	 * @return void
	 */
	public function testListColumnsFailureIsEmpty(): void {
		$reader = $this->reader();
		ColumnService::$replies['findAllByTable'] = new RuntimeException('boom');

		$this->assertSame([], $reader->listColumns(tableId: 5, userId: 'alice'));
		$this->assertSame(['warning [ObjectSource:tables] could not list columns for table 5: boom'], $this->warnings);
	}//end testListColumnsFailureIsEmpty()

	/**
	 * Table rows go through findAllByTable with the page pushed down; non-entities are skipped.
	 *
	 * @return void
	 */
	public function testTableRows(): void {
		$reader = $this->reader();
		RowService::$replies['findAllByTable'] = [$this->rowEntity(42), 'not an entity'];

		$this->assertSame([$this->rowDescriptor(42)], $this->tableRows($reader));
		$this->assertSame([['findAllByTable', 5, 'alice', 10, 20]], RowService::$calls);
	}//end testTableRows()

	/**
	 * View rows go through findAllByView with the page pushed down.
	 *
	 * @return void
	 */
	public function testViewRows(): void {
		$reader = $this->reader();
		RowService::$replies['findAllByView'] = [$this->rowEntity(43)];

		$this->assertSame([$this->rowDescriptor(43)], $this->viewRows($reader));
		$this->assertSame([['findAllByView', 9, 'alice', 10, 20]], RowService::$calls);
	}//end testViewRows()

	/**
	 * A failing row read logs and degrades to empty.
	 *
	 * @return void
	 */
	public function testRowsFailureIsEmpty(): void {
		$reader = $this->reader();
		RowService::$replies['findAllByTable'] = new RuntimeException('boom');

		$this->assertSame([], $this->tableRows($reader));
		$this->assertSame(['warning [ObjectSource:tables] could not read rows for 5: boom'], $this->warnings);
	}//end testRowsFailureIsEmpty()

	/**
	 * A row whose metadata getters fail keeps a usable descriptor.
	 *
	 * @return void
	 */
	public function testRowWithoutMetadata(): void {
		$reader = $this->reader();
		RowService::$replies['findAllByTable'] = [
			new FakeEntity(['getCreatedAt' => '', 'getLastEditAt' => 5, 'getCreatedBy' => ['x'], 'getData' => 'no array']),
		];

		$this->assertSame(
			[['id' => 0, 'createdBy' => null, 'createdAt' => null, 'lastEditBy' => null, 'lastEditAt' => null, 'cells' => []]],
			$this->tableRows($reader)
		);
	}//end testRowWithoutMetadata()

	/**
	 * A table count takes the table id only.
	 *
	 * @return void
	 */
	public function testCountTable(): void {
		$reader = $this->reader();
		RowService::$replies['getRowsCount'] = '12';

		$this->assertSame(12, $this->countTable($reader));
		$this->assertSame([['getRowsCount', 5]], RowService::$calls);
	}//end testCountTable()

	/**
	 * A view count finds the View entity first and passes that.
	 *
	 * @return void
	 */
	public function testCountView(): void {
		$reader = $this->reader();
		$view = new FakeEntity([]);
		ViewService::$replies['find'] = $view;
		RowService::$replies['getViewRowsCount'] = 3;

		$this->assertSame(3, $this->countView($reader));
		$this->assertSame([['find', 9, true, 'alice']], ViewService::$calls);
		$this->assertSame([['getViewRowsCount', $view, 'alice']], RowService::$calls);
	}//end testCountView()

	/**
	 * Without a view service a view count is 0.
	 *
	 * @return void
	 */
	public function testCountViewWithoutViewService(): void {
		$reader = $this->reader([ViewService::class]);

		$this->assertSame(0, $this->countView($reader));
		$this->assertSame([], RowService::$calls);
	}//end testCountViewWithoutViewService()

	/**
	 * A failing count logs and degrades to 0.
	 *
	 * @return void
	 */
	public function testCountFailureIsZero(): void {
		$reader = $this->reader();
		RowService::$replies['getRowsCount'] = new RuntimeException('boom');

		$this->assertSame(0, $this->countTable($reader));
		$this->assertSame(['warning [ObjectSource:tables] could not count rows for 5: boom'], $this->warnings);
	}//end testCountFailureIsZero()

	/**
	 * A failing view lookup logs against the view id and degrades to 0.
	 *
	 * @return void
	 */
	public function testCountViewFailureIsZero(): void {
		$reader = $this->reader();
		ViewService::$replies['find'] = new RuntimeException('denied');

		$this->assertSame(0, $this->countView($reader));
		$this->assertSame(['warning [ObjectSource:tables] could not count rows for 9: denied'], $this->warnings);
	}//end testCountViewFailureIsZero()

	/**
	 * Tables is asked about the bound table and the asking user before the row is read.
	 *
	 * @return void
	 */
	public function testFindRowAsksPermissionFirst(): void {
		$reader = $this->reader();
		PermissionsService::$replies['canReadRowsByElementId'] = true;
		RowService::$replies['find'] = new FakeEntity(['getId' => 42, 'getTableId' => 5, 'getData' => []]);

		$this->assertSame(42, $this->boundRow($reader)['id']);
		$this->assertSame([['canReadRowsByElementId', 5, 'table', 'alice']], PermissionsService::$calls);
		$this->assertSame([['find', 42]], RowService::$calls);
	}//end testFindRowAsksPermissionFirst()

	/**
	 * Only a literal true grants the read.
	 *
	 * @return void
	 */
	public function testFindRowNeedsLiteralTrue(): void {
		$reader = $this->reader();
		PermissionsService::$replies['canReadRowsByElementId'] = 1;

		$this->assertNull($this->boundRow($reader));
		$this->assertSame([], RowService::$calls);
	}//end testFindRowNeedsLiteralTrue()

	/**
	 * A failing permission check refuses, logs, and reads nothing.
	 *
	 * @return void
	 */
	public function testFindRowPermissionFailureRefuses(): void {
		$reader = $this->reader();
		PermissionsService::$replies['canReadRowsByElementId'] = new RuntimeException('boom');

		$this->assertNull($this->boundRow($reader));
		$this->assertSame([], RowService::$calls);
		$this->assertSame(['warning [ObjectSource:tables] could not read row 42: boom'], $this->warnings);
	}//end testFindRowPermissionFailureRefuses()

	/**
	 * Without the permission service nothing is read.
	 *
	 * @return void
	 */
	public function testFindRowWithoutPermissionServiceRefuses(): void {
		$reader = $this->reader([PermissionsService::class]);

		$this->assertNull($this->boundRow($reader));
		$this->assertSame([], RowService::$calls);
		$this->assertCount(1, $this->warnings, 'only the failed lookup is logged');
	}//end testFindRowWithoutPermissionServiceRefuses()

	/**
	 * Without the row service the permission check is not even made.
	 *
	 * @return void
	 */
	public function testFindRowWithoutRowServiceRefuses(): void {
		$reader = $this->reader([RowService::class]);

		$this->assertNull($this->boundRow($reader));
		$this->assertSame([], PermissionsService::$calls);
	}//end testFindRowWithoutRowServiceRefuses()

	/**
	 * A failing read and a reply that is no entity are both null.
	 *
	 * @return void
	 */
	public function testFindRowUnreadableRowIsNull(): void {
		$reader = $this->reader();
		PermissionsService::$replies['canReadRowsByElementId'] = true;
		RowService::$replies['find'] = new RuntimeException('gone');
		$this->assertNull($this->boundRow($reader));
		$this->assertSame(['warning [ObjectSource:tables] could not read row 42: gone'], $this->warnings);

		RowService::$replies['find'] = 'not an entity';
		$this->assertNull($this->boundRow($reader));
	}//end testFindRowUnreadableRowIsNull()

	/**
	 * A row with no table id is not a row of the bound table.
	 *
	 * @return void
	 */
	public function testFindRowWithoutTableIdIsNull(): void {
		$reader = $this->reader();
		PermissionsService::$replies['canReadRowsByElementId'] = true;
		RowService::$replies['find'] = new FakeEntity(['getId' => 42]);

		$this->assertNull($this->boundRow($reader));
	}//end testFindRowWithoutTableIdIsNull()
}//end class
