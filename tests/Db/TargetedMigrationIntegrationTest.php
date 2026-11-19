<?php

/**
 * TargetedMigrationIntegrationTest: a step run on its own reads and changes only the tables it names.
 *
 * Against the real database: the diff of a table with itself, the statements
 * the platform writes for it and what the catalogue says afterwards cannot be
 * shown with doubles.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Db;

use Closure;
use LogicException;
use OC\DB\Connection;
use OC\DB\MigrationService;
use OC\Migration\NullOutput;
use OCA\OpenRegister\Migration\TargetedMigrationStep;
use OCA\OpenRegister\Migration\TargetedSchema;
use OCA\OpenRegister\Migration\TargetedSchemaChange;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Migration\TargetedMigrationStep
 * @covers \OCA\OpenRegister\Migration\TargetedSchema
 * @covers \OCA\OpenRegister\Migration\TargetedSchemaChange
 *
 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
 */
class TargetedMigrationIntegrationTest extends TestCase {

	private Connection $connection;

	/**
	 * The scratch table of this test, without prefix.
	 *
	 * @var string
	 */
	private string $table;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = \OC::$server->get(Connection::class);
		$this->table = 'or_tm_' . substr(md5(uniqid('', true)), 0, 10);
	}//end setUp()

	protected function tearDown(): void {
		foreach ([$this->table, $this->table . '_b'] as $table) {
			if ($this->connection->tableExists($table) === true) {
				$this->connection->dropTable($table);
			}
		}

		\OC::$server->get(IConfig::class)->deleteSystemValue(TargetedMigrationStep::CONFIG_KEY);
		parent::tearDown();
	}//end tearDown()

	/**
	 * The column names of a scratch table as the catalogue has them.
	 *
	 * @param string $table The table, without prefix.
	 *
	 * @return list<string>
	 */
	private function columns(string $table): array {
		return array_keys($this->connection->createSchemaManager()->introspectTable($this->connection->getPrefix() . $table)->getColumns());
	}//end columns()

	/**
	 * Apply one change the way a targeted step does.
	 *
	 * @param callable(ISchemaWrapper): void $change What the step does with its schema.
	 *
	 * @return void
	 */
	private function apply(callable $change): void {
		$targeted = new TargetedSchemaChange(connection: $this->connection);
		$change($targeted->wrapper());
		$targeted->apply();
	}//end apply()

	/**
	 * A schema closure as Nextcloud's MigrationService makes them, which fails the test when it is called.
	 *
	 * @return Closure
	 */
	private function nextcloudsClosure(): Closure {
		$service = new MigrationService('openregister', $this->connection, new NullOutput());

		return Closure::bind(
			function (): never {
				throw new LogicException('Nextcloud read the whole schema');
			},
			$service,
			MigrationService::class
		);
	}//end nextcloudsClosure()

	/**
	 * A step that creates the scratch table with an id and a name.
	 *
	 * @return TargetedMigrationStep
	 */
	private function creatingStep(): TargetedMigrationStep {
		$table = $this->table;

		return new class($table) extends TargetedMigrationStep {
			public function __construct(private readonly string $table) {
			}

			protected function changeTargetedSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
				$schema = $schemaClosure();
				if ($schema->hasTable($this->table) === false) {
					$created = $schema->createTable($this->table);
					$created->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
					$created->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 64]);
					$created->setPrimaryKey(['id']);
				}

				return $schema;
			}
		};
	}//end creatingStep()

	public function testATableIsCreatedChangedAndDroppedFromItsOwnDefinitionAlone(): void {
		$this->apply(
			function (ISchemaWrapper $schema): void {
				$table = $schema->createTable($this->table);
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
				$table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 64]);
				$table->setPrimaryKey(['id']);
			}
		);
		$this->assertSame(['id', 'name'], $this->columns($this->table));

		$this->apply(
			function (ISchemaWrapper $schema): void {
				$table = $schema->getTable($this->table);
				$table->addColumn('note', Types::TEXT, ['notnull' => false]);
				$table->addColumn('payload', Types::STRING, ['notnull' => false, 'length' => 5000]);
				$table->addIndex(['name'], $this->table . '_name');
			}
		);
		$live = $this->connection->createSchemaManager()->introspectTable($this->connection->getPrefix() . $this->table);
		$this->assertSame(['id', 'name', 'note', 'payload'], array_keys($live->getColumns()));
		$this->assertTrue($live->hasIndex($this->table . '_name'));
		// Nextcloud's migrator stores a string longer than 4000 as text on every database.
		$this->assertSame(Types::TEXT, \Doctrine\DBAL\Types\Type::lookupName($live->getColumn('payload')->getType()));

		$this->apply(
			function (ISchemaWrapper $schema): void {
				$schema->getTable($this->table)->dropColumn('note');
			}
		);
		$this->assertSame(['id', 'name', 'payload'], $this->columns($this->table));

		$this->apply(
			function (ISchemaWrapper $schema): void {
				$schema->dropTable($this->table);
			}
		);
		$this->assertFalse($this->connection->tableExists($this->table));
	}//end testATableIsCreatedChangedAndDroppedFromItsOwnDefinitionAlone()

	public function testAStepThatChangesNothingKeepsTheTableAndItsRows(): void {
		$this->apply(
			function (ISchemaWrapper $schema): void {
				$table = $schema->createTable($this->table);
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
				$table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 64]);
				$table->setPrimaryKey(['id']);
			}
		);
		$this->connection->executeStatement('INSERT INTO `*PREFIX*' . $this->table . '` (`name`) VALUES (?)', ['kept']);

		$this->apply(
			function (ISchemaWrapper $schema): void {
				$this->assertTrue($schema->hasTable($this->table));
				$this->assertTrue($schema->getTable($this->table)->hasColumn('name'));
			}
		);

		$this->assertSame('kept', $this->connection->executeQuery('SELECT `name` FROM `*PREFIX*' . $this->table . '`')->fetchOne());
		$this->assertSame(['id', 'name'], $this->columns($this->table));
	}//end testAStepThatChangesNothingKeepsTheTableAndItsRows()

	public function testAStepThatChangesNothingHasNoStatements(): void {
		$this->apply(
			function (ISchemaWrapper $schema): void {
				$table = $schema->createTable($this->table);
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
				$table->addColumn('body', Types::TEXT, ['notnull' => false, 'comment' => 'what was said']);
				$table->addColumn('seen', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
				$table->setPrimaryKey(['id']);
				$table->addIndex(['seen'], $this->table . '_seen');
			}
		);

		$again = new TargetedSchemaChange(connection: $this->connection);
		$again->wrapper()->getTable($this->table)->hasColumn('body');

		$this->assertSame([], $again->statements());
	}//end testAStepThatChangesNothingHasNoStatements()

	/**
	 * Nextcloud's SQLite migrator drops column comments before it compares; without that an
	 * unchanged table differs from itself and is rebuilt by every later step.
	 *
	 * @return void
	 */
	public function testOnSqliteAColumnCommentDoesNotMakeAnUnchangedTableDiffer(): void {
		$this->onSqlite(
			function (Connection $sqlite): void {
				$create = new TargetedSchemaChange(connection: $sqlite);
				$table = $create->wrapper()->createTable($this->table);
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
				$table->addColumn('body', Types::TEXT, ['notnull' => false, 'comment' => 'what was said']);
				$table->setPrimaryKey(['id']);
				$create->apply();

				$again = new TargetedSchemaChange(connection: $sqlite);
				$again->wrapper()->getTable($this->table)->addColumn('note', Types::STRING, ['notnull' => false, 'length' => 64, 'comment' => 'why']);
				$again->apply();

				$unchanged = new TargetedSchemaChange(connection: $sqlite);
				$this->assertTrue($unchanged->wrapper()->getTable($this->table)->hasColumn('note'));
				$this->assertSame([], $unchanged->statements());
			}
		);
	}//end testOnSqliteAColumnCommentDoesNotMakeAnUnchangedTableDiffer()

	/**
	 * Run a test on a SQLite database of its own, removed afterwards.
	 *
	 * @param callable(Connection): void $test The test.
	 *
	 * @return void
	 */
	private function onSqlite(callable $test): void {
		$file = tempnam(sys_get_temp_dir(), 'or_tm_') . '.db';
		$sqlite = \OC::$server->get(\OC\DB\ConnectionFactory::class)->getConnection('sqlite3', ['path' => $file]);

		try {
			$test($sqlite);
		} finally {
			$sqlite->close();
			foreach (glob($file . '*') as $leftover) {
				unlink($leftover);
			}

			unlink(substr($file, 0, -3));
		}
	}//end onSqlite()

	/**
	 * Run a step whose statements fail after the first: it creates a second table, then asks for a
	 * unique index over two equal rows of the scratch table.
	 *
	 * @param Connection $connection The connection to run on.
	 *
	 * @return \Throwable|null What the database refused the step with.
	 */
	private function failHalfway(Connection $connection): ?\Throwable {
		$create = new TargetedSchemaChange(connection: $connection);
		$table = $create->wrapper()->createTable($this->table);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->setPrimaryKey(['id']);
		$create->apply();
		$connection->executeStatement('INSERT INTO `*PREFIX*' . $this->table . '` (`name`) VALUES (?), (?)', ['twice', 'twice']);

		$failing = new TargetedSchemaChange(connection: $connection);
		$second = $failing->wrapper()->createTable($this->table . '_b');
		$second->addColumn('id', Types::BIGINT, ['notnull' => true]);
		$second->setPrimaryKey(['id']);
		$failing->wrapper()->getTable($this->table)->addUniqueIndex(['name'], $this->table . '_uq');

		try {
			$failing->apply();
		} catch (\Throwable $refused) {
			return $refused;
		}

		return null;
	}//end failHalfway()

	/**
	 * Left open, the transaction of a failed step takes every later statement of the process with it:
	 * refused on PostgreSQL, silently discarded at disconnect on SQLite.
	 *
	 * @return void
	 */
	public function testAStepThatFailsHalfwayLeavesNoTransactionOpen(): void {
		$refused = $this->failHalfway($this->connection);

		$this->assertInstanceOf(\Doctrine\DBAL\Exception::class, $refused);
		$this->assertFalse($this->connection->isTransactionActive());
	}//end testAStepThatFailsHalfwayLeavesNoTransactionOpen()

	public function testWhereDdlIsTransactionalAStepThatFailsHalfwayIsUndoneAsAWhole(): void {
		$this->onSqlite(
			function (Connection $sqlite): void {
				$refused = $this->failHalfway($sqlite);

				$this->assertInstanceOf(\Doctrine\DBAL\Exception::class, $refused);
				$this->assertFalse($sqlite->isTransactionActive(), 'the transaction of the failed step is still open');
				$this->assertFalse($sqlite->tableExists($this->table . '_b'), 'the table created before the refused statement is still there');
				$this->assertSame(2, (int)$sqlite->executeQuery('SELECT COUNT(*) FROM `*PREFIX*' . $this->table . '`')->fetchOne());
				$this->assertSame(['id', 'name'], array_keys($sqlite->createSchemaManager()->introspectTable($sqlite->getPrefix() . $this->table)->getColumns()));
			}
		);
	}//end testWhereDdlIsTransactionalAStepThatFailsHalfwayIsUndoneAsAWhole()

	public function testOnlyTheTablesAStepNamesAreRead(): void {
		$schema = new TargetedSchema(manager: $this->connection->createSchemaManager());

		$this->assertTrue($schema->hasTable($this->connection->getPrefix() . 'migrations'));
		$this->assertFalse($schema->hasTable($this->connection->getPrefix() . $this->table));

		$this->assertCount(1, $schema->touched());
		$this->assertCount(1, $schema->before()->getTables());
	}//end testOnlyTheTablesAStepNamesAreRead()

	public function testAStepNextcloudRunsOnItsOwnAppliesItselfWithoutNextcloudReadingTheSchema(): void {
		$step = $this->creatingStep();
		$closure = $this->nextcloudsClosure();

		// The order and the closures of MigrationService::executeStep().
		$step->preSchemaChange(new NullOutput(), $closure, []);
		$returned = $step->changeSchema(new NullOutput(), $closure, []);
		$step->postSchemaChange(new NullOutput(), $closure, []);

		$this->assertNull($returned, 'Nextcloud must be told nothing is left to apply');
		$this->assertSame(['id', 'name'], $this->columns($this->table));
	}//end testAStepNextcloudRunsOnItsOwnAppliesItselfWithoutNextcloudReadingTheSchema()

	public function testAnInstallKeepsNextcloudsSharedSchema(): void {
		$step = $this->creatingStep();

		// No preSchemaChange(): that is MigrationService::migrateSchemaOnly().
		$this->expectException(LogicException::class);
		$step->changeSchema(new NullOutput(), $this->nextcloudsClosure(), []);
	}//end testAnInstallKeepsNextcloudsSharedSchema()

	public function testTheConfigSwitchLeavesAStepToNextcloud(): void {
		\OC::$server->get(IConfig::class)->setSystemValue(TargetedMigrationStep::CONFIG_KEY, false);
		$step = $this->creatingStep();
		$closure = $this->nextcloudsClosure();

		$step->preSchemaChange(new NullOutput(), $closure, []);
		$this->expectException(LogicException::class);
		$step->changeSchema(new NullOutput(), $closure, []);
	}//end testTheConfigSwitchLeavesAStepToNextcloud()
}//end class
