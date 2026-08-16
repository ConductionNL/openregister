<?php

/**
 * Tests for the Dutch-to-English column migration.
 *
 * WHY THIS EXISTS. OpenRegister materialises every schema property as a real
 * COLUMN, and MagicMapper ADDS a column when the snake_cased name is missing but
 * never RENAMES one. So renaming a property in the register definition is a DATA
 * MIGRATION: without this repair step the rows keep their values in a column
 * nothing reads any more, and the app silently starts serving nulls.
 *
 * The double is a FAKE SCHEMA rather than a set of expected calls. Asserting
 * "a rename was issued" against a mock only proves the test and the code agree
 * on a string; running the step against a described set of tables and columns
 * and then asking what SQL came out tests the decision the step actually makes.
 *
 * The statement and result doubles are HAND-WRITTEN rather than createMock()d.
 * OCP\DB\IPreparedStatement::bindValue() defaults its type argument to
 * Doctrine\DBAL\ParameterType::STRING, and this app ships doctrine/deprecations
 * without doctrine/dbal. PHPUnit's mock generator evaluates that default while
 * generating the double, so createMock() throws — and RenameDutchColumns CATCHES
 * Throwable around its information_schema reads, so the thrown error surfaces as
 * "no shard tables on this install" and the tests pass while migrating nothing.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.openregister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\RenameDutchColumns;
use OCP\DB\Exception;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A result that yields a fixed list of scalars.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.openregister.app
 */
final class FakeResult implements IResult {
	/**
	 * @param array<int, mixed> $rows Rows this result yields.
	 */
	public function __construct(private array $rows = []) {
	}//end __construct()

	/**
	 * @return bool
	 */
	public function closeCursor(): bool {
		return true;
	}//end closeCursor()

	/**
	 * @param int $fetchMode Ignored.
	 *
	 * @return mixed
	 */
	public function fetch(int $fetchMode = 2): mixed {
		return array_shift($this->rows);
	}//end fetch()

	/**
	 * @param int $fetchMode Ignored.
	 *
	 * @return array<int, mixed>
	 */
	public function fetchAll(int $fetchMode = 2): array {
		return $this->rows;
	}//end fetchAll()

	/**
	 * @return mixed
	 */
	public function fetchColumn(): mixed {
		return ($this->rows[0] ?? false);
	}//end fetchColumn()

	/**
	 * @return mixed
	 */
	public function fetchOne(): mixed {
		return ($this->rows[0] ?? false);
	}//end fetchOne()

	/**
	 * @return array<string, mixed>|false
	 */
	public function fetchAssociative(): array|false {
		$row = array_shift($this->rows);
		return (is_array($row) === true ? $row : false);
	}//end fetchAssociative()

	/**
	 * @return array<int, mixed>|false
	 */
	public function fetchNumeric(): array|false {
		$row = array_shift($this->rows);
		return (is_array($row) === true ? array_values($row) : false);
	}//end fetchNumeric()

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function fetchAllAssociative(): array {
		return array_values(array_filter($this->rows, 'is_array'));
	}//end fetchAllAssociative()

	/**
	 * @return array<int, array<int, mixed>>
	 */
	public function fetchAllNumeric(): array {
		return array_map('array_values', array_values(array_filter($this->rows, 'is_array')));
	}//end fetchAllNumeric()

	/**
	 * @return array<int, mixed>
	 */
	public function fetchFirstColumn(): array {
		return array_values($this->rows);
	}//end fetchFirstColumn()

	/**
	 * @return \Traversable<int, array<int, mixed>>
	 */
	public function iterateNumeric(): \Traversable {
		return new \ArrayIterator($this->fetchAllNumeric());
	}//end iterateNumeric()

	/**
	 * @return \Traversable<int, array<string, mixed>>
	 */
	public function iterateAssociative(): \Traversable {
		return new \ArrayIterator($this->fetchAllAssociative());
	}//end iterateAssociative()

	/**
	 * @return int
	 */
	public function rowCount(): int {
		return count($this->rows);
	}//end rowCount()
}//end class

/**
 * A prepared statement over a fixed set of information_schema rows.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.openregister.app
 */
final class FakeStatement implements IPreparedStatement {
	/** @var array<string, mixed> Values bound by the caller. */
	public array $bound = [];

	/** @var array<int, array<string, string>> Rows still to yield. */
	private array $rows = [];

	/**
	 * @param callable $rowsFor Given the bound values, return the rows to yield.
	 */
	public function __construct(private $rowsFor) {
	}//end __construct()

	/**
	 * @return bool
	 */
	public function closeCursor(): bool {
		return true;
	}//end closeCursor()

	/**
	 * @param int $fetchMode Ignored.
	 *
	 * @return mixed
	 */
	public function fetch(int $fetchMode = 2): mixed {
		$row = array_shift($this->rows);
		return ($row ?? false);
	}//end fetch()

	/**
	 * @param int $fetchMode Ignored.
	 *
	 * @return array<int, mixed>
	 */
	public function fetchAll(int $fetchMode = 2): array {
		$rows = $this->rows;
		$this->rows = [];
		return $rows;
	}//end fetchAll()

	/**
	 * @return mixed
	 */
	public function fetchColumn(): mixed {
		return false;
	}//end fetchColumn()

	/**
	 * @return mixed
	 */
	public function fetchOne(): mixed {
		return false;
	}//end fetchOne()

	/**
	 * @param mixed $param Placeholder name.
	 * @param mixed $value Bound value.
	 * @param mixed $type  Ignored.
	 *
	 * @return bool
	 */
	public function bindValue($param, $value, $type = null): bool {
		$this->bound[(string)$param] = $value;
		return true;
	}//end bindValue()

	/**
	 * @param mixed $param    Placeholder name.
	 * @param mixed $variable Bound variable.
	 * @param mixed $type     Ignored.
	 * @param mixed $length   Ignored.
	 *
	 * @return bool
	 */
	public function bindParam($param, &$variable, $type = null, $length = null): bool {
		$this->bound[(string)$param] = $variable;
		return true;
	}//end bindParam()

	/**
	 * Resolve the rows now that the bindings are known.
	 *
	 * @param mixed $params Ignored.
	 *
	 * @return IResult
	 */
	public function execute($params = null): IResult {
		$this->rows = ($this->rowsFor)($this->bound);
		return new FakeResult([]);
	}//end execute()

	/**
	 * @return int
	 */
	public function rowCount(): int {
		return count($this->rows);
	}//end rowCount()
}//end class

/**
 * @covers \OCA\OpenRegister\Repair\RenameDutchColumns
 */
class RenameDutchColumnsTest extends TestCase {
	/** @var array<int, string> Every statement the step executed. */
	private array $executed = [];

	/** @var array<int, string> Messages the step wrote to the repair output. */
	private array $info = [];

	/** @var array<int, string> Warnings the step logged. */
	private array $warnings = [];

	/**
	 * Build the step over a described database.
	 *
	 * @param array<int, int>              $registerIds    Ids the register lookup returns.
	 * @param array<int, string>           $tables         Table names information_schema reports.
	 * @param array<string, array<string>> $columns        Column names per table.
	 * @param string|null                  $failOn         Substring of a statement that should fail.
	 * @param bool                         $registersThrow Whether the register lookup throws.
	 * @param bool                         $tablesThrow    Whether the table listing throws.
	 *
	 * @return RenameDutchColumns
	 */
	private function step(
		array $registerIds,
		array $tables,
		array $columns,
		?string $failOn = null,
		bool $registersThrow = false,
		bool $tablesThrow = false,
	): RenameDutchColumns {
		$this->executed = [];
		$this->info = [];
		$this->warnings = [];

		$db = $this->createMock(IDBConnection::class);

		if ($registersThrow === true) {
			$db->method('executeQuery')->willThrowException(new Exception('registers unavailable'));
		} else {
			$db->method('executeQuery')->willReturnCallback(
				static fn (): IResult => new FakeResult($registerIds)
			);
		}

		// information_schema goes through prepare(): the table listing, then one
		// column listing per table. Dispatch on the SQL so the order of the step's
		// own calls is not baked into the test.
		if ($tablesThrow === true) {
			$db->method('prepare')->willThrowException(new Exception('information_schema unavailable'));
		} else {
			$db->method('prepare')->willReturnCallback(
				static function (string $sql) use ($tables, $columns): IPreparedStatement {
					if (str_contains($sql, 'information_schema.tables') === true) {
						return new FakeStatement(
							static fn (): array => array_map(
								static fn (string $t): array => ['table_name' => $t],
								$tables
							)
						);
					}

					return new FakeStatement(
						static function (array $bound) use ($columns): array {
							$asked = (string)($bound['table'] ?? '');
							return array_map(
								static fn (string $c): array => ['column_name' => $c],
								($columns[$asked] ?? [])
							);
						}
					);
				}
			);
		}//end if

		$db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($failOn): int {
				if ($failOn !== null && str_contains($sql, $failOn) === true) {
					throw new Exception('statement rejected');
				}

				$this->executed[] = $sql;
				return 1;
			}
		);

		$platform = new class {
			/**
			 * @param string $identifier Identifier to quote.
			 *
			 * @return string
			 */
			public function quoteSingleIdentifier(string $identifier): string {
				return '"' . $identifier . '"';
			}
		};
		$db->method('getDatabasePlatform')->willReturn($platform);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new RenameDutchColumns($db, $logger);
	}//end step()

	/**
	 * Capture what the step reported.
	 *
	 * @return IOutput
	 */
	private function repairOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->info[] = $message;
			}
		);
		return $output;
	}//end repairOutput()

	/**
	 * The step names itself for the repair log.
	 *
	 * @return void
	 */
	public function testGetNameDescribesTheMigration(): void {
		$step = $this->step([], [], []);
		$this->assertStringContainsString('openregister', strtolower($step->getName()));
	}//end testGetNameDescribesTheMigration()

	/**
	 * An install with no shard tables is a no-op, not an error.
	 *
	 * @return void
	 */
	public function testNoShardTablesDoesNothing(): void {
		$step = $this->step([], [], []);
		$step->run($this->repairOutput());
		$this->assertSame([], $this->executed);
		$this->assertStringContainsString('nothing to do', implode(' ', $this->info));
	}//end testNoShardTablesDoesNothing()

	/**
	 * Old column present, English one absent: rename it.
	 *
	 * @return void
	 */
	public function testRenamesWhenOnlyTheDutchColumnExists(): void {
		$table = 'oc_openregister_table_7_3';
		$step = $this->step([7], [$table], [$table => ['id', 'classificatie']]);
		$step->run($this->repairOutput());
		$this->assertCount(1, $this->executed);
		$this->assertStringContainsString('RENAME COLUMN "classificatie" TO "classification"', $this->executed[0]);
	}//end testRenamesWhenOnlyTheDutchColumnExists()

	/**
	 * Every mapped column in one table is migrated, not just the first.
	 *
	 * @return void
	 */
	public function testAllMappedColumnsInATableAreMigrated(): void {
		$table = 'oc_openregister_table_7_3';
		$step = $this->step(
			[7],
			[$table],
			[$table => ['id', 'classificatie', 'bestandsnaam', 'eind_datum', 'functie']]
		);
		$step->run($this->repairOutput());
		$this->assertCount(4, $this->executed);
		$this->assertStringContainsString('"eind_datum" TO "end_date"', implode(' ', $this->executed));
	}//end testAllMappedColumnsInATableAreMigrated()

	/**
	 * BOTH columns present: back-fill, never rename.
	 *
	 * MagicMapper ADDS the English column the first time it writes the renamed
	 * property, so by the time the repair runs both can exist with the data split
	 * across them. Renaming would collide; dropping the old one would lose rows
	 * written before the rename. Copy only where the destination is still null,
	 * so a value already written through the new name wins.
	 *
	 * @return void
	 */
	public function testBackfillsWhenBothColumnsExist(): void {
		$table = 'oc_openregister_table_7_3';
		$step = $this->step([7], [$table], [$table => ['id', 'classificatie', 'classification']]);
		$step->run($this->repairOutput());
		$this->assertCount(1, $this->executed);
		$this->assertStringContainsString('UPDATE', $this->executed[0]);
		$this->assertStringContainsString('"classification" = "classificatie"', $this->executed[0]);
		$this->assertStringContainsString('"classification" IS NULL', $this->executed[0]);
		$this->assertStringNotContainsString('DROP', $this->executed[0]);
	}//end testBackfillsWhenBothColumnsExist()

	/**
	 * A table without a mapped column is left alone.
	 *
	 * @return void
	 */
	public function testTableWithoutTheDutchColumnIsUntouched(): void {
		$table = 'oc_openregister_table_7_3';
		$step = $this->step([7], [$table], [$table => ['id', 'classification']]);
		$step->run($this->repairOutput());
		$this->assertSame([], $this->executed);
	}//end testTableWithoutTheDutchColumnIsUntouched()

	/**
	 * A shard of a register this app does not own is not migrated.
	 *
	 * The marker match must be anchored to THIS app's register ids. Matching
	 * `openregister_table_` alone would rewrite another app's columns on a shared
	 * instance, which is a data-loss bug in someone else's app.
	 *
	 * @return void
	 */
	public function testForeignRegisterShardIsNotMigrated(): void {
		$mine = 'oc_openregister_table_7_3';
		$theirs = 'oc_openregister_table_99_1';
		$step = $this->step(
			[7],
			[$mine, $theirs],
			[$mine => ['id'], $theirs => ['classificatie']]
		);
		$step->run($this->repairOutput());
		$this->assertSame([], $this->executed);
	}//end testForeignRegisterShardIsNotMigrated()

	/**
	 * The marker must be followed by digits.
	 *
	 * `openregister_table_7_backup` contains the marker for register 7 but is not
	 * a shard; a substring match would migrate it.
	 *
	 * @return void
	 */
	public function testNonNumericSuffixIsNotAShard(): void {
		$table = 'oc_openregister_table_7_backup';
		$step = $this->step([7], [$table], [$table => ['classificatie']]);
		$step->run($this->repairOutput());
		$this->assertSame([], $this->executed);
	}//end testNonNumericSuffixIsNotAShard()

	/**
	 * A failed statement is swallowed and logged, leaving the column as it was.
	 *
	 * A repair step that throws blocks the upgrade for every step behind it.
	 *
	 * @return void
	 */
	public function testFailedStatementIsLoggedAndDoesNotThrow(): void {
		$table = 'oc_openregister_table_7_3';
		$step = $this->step([7], [$table], [$table => ['classificatie']], failOn: 'RENAME COLUMN');
		$step->run($this->repairOutput());
		$this->assertSame([], $this->executed);
		$this->assertNotEmpty($this->warnings);
	}//end testFailedStatementIsLoggedAndDoesNotThrow()

	/**
	 * If the registers cannot be read, migrate nothing.
	 *
	 * @return void
	 */
	public function testUnreadableRegistersSkipsQuietly(): void {
		$step = $this->step([7], ['oc_openregister_table_7_3'], [], registersThrow: true);
		$step->run($this->repairOutput());
		$this->assertSame([], $this->executed);
		$this->assertNotEmpty($this->warnings);
	}//end testUnreadableRegistersSkipsQuietly()

	/**
	 * If information_schema cannot be listed, migrate nothing.
	 *
	 * @return void
	 */
	public function testUnreadableTableListSkipsQuietly(): void {
		$step = $this->step([7], ['oc_openregister_table_7_3'], [], tablesThrow: true);
		$step->run($this->repairOutput());
		$this->assertSame([], $this->executed);
		$this->assertNotEmpty($this->warnings);
	}//end testUnreadableTableListSkipsQuietly()

	/**
	 * Every shard of the register is migrated, not just the first.
	 *
	 * @return void
	 */
	public function testAllShardsOfTheRegisterAreMigrated(): void {
		$a = 'oc_openregister_table_7_1';
		$b = 'oc_openregister_table_7_2';
		$step = $this->step([7], [$a, $b], [$a => ['classificatie'], $b => ['classificatie']]);
		$step->run($this->repairOutput());
		$this->assertCount(2, $this->executed);
	}//end testAllShardsOfTheRegisterAreMigrated()

	/**
	 * The summary counts what happened.
	 *
	 * @return void
	 */
	public function testSummaryReportsCounts(): void {
		$a = 'oc_openregister_table_7_1';
		$b = 'oc_openregister_table_7_2';
		$step = $this->step(
			[7],
			[$a, $b],
			[$a => ['classificatie'], $b => ['classificatie', 'classification']]
		);
		$step->run($this->repairOutput());
		$summary = implode(' ', $this->info);
		$this->assertStringContainsString('1 renamed', $summary);
		$this->assertStringContainsString('1 back-filled', $summary);
	}//end testSummaryReportsCounts()
}//end class
