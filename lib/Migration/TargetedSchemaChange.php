<?php

/**
 * TargetedSchemaChange: hand a migration step a schema of the tables it names, and apply what it changed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCP\DB\ISchemaWrapper;

/**
 * One migration step's change to the database, worked out from the tables the step touched.
 *
 * Does for those tables what Nextcloud's `MigrationService` and `Migrator` do
 * for the whole database: compare the tables as they were with the tables as
 * the step left them, and run the statements that make the difference. The
 * step works on Nextcloud's own schema wrapper, so it sees the interface it
 * always saw.
 *
 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
 */
final class TargetedSchemaChange {

	/**
	 * The schema of the tables the step names.
	 *
	 * @var TargetedSchema
	 */
	private readonly TargetedSchema $schema;

	/**
	 * Nextcloud's wrapper around that schema: what the step is handed.
	 *
	 * @var SchemaWrapper
	 */
	private readonly SchemaWrapper $wrapper;

	/**
	 * Constructor.
	 *
	 * @param Connection $connection Nextcloud's database connection.
	 */
	public function __construct(private readonly Connection $connection) {
		$this->schema = new TargetedSchema(manager: $connection->createSchemaManager());
		$this->wrapper = new SchemaWrapper($connection, $this->schema);
	}//end __construct()

	/**
	 * The schema wrapper to hand the step.
	 *
	 * @return ISchemaWrapper
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	public function wrapper(): ISchemaWrapper {
		return $this->wrapper;
	}//end wrapper()

	/**
	 * Bring the database to what the step left in the wrapper.
	 *
	 * Where the database has transactional DDL (PostgreSQL, SQLite) a step that
	 * fails halfway is undone as a whole and its transaction is closed, so the
	 * rest of the process keeps a connection it can use.
	 *
	 * @return void
	 *
	 * @throws \Throwable When a statement fails.
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	public function apply(): void {
		$statements = $this->statements();

		// MySQL and MariaDB commit on every DDL statement, so a transaction around them is one in name only.
		if ($this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
			$this->run(statements: $statements);
			$this->wrapper->performDropTableCalls();
			return;
		}

		$this->connection->beginTransaction();
		try {
			$this->run(statements: $statements);
			$this->connection->commit();
		} catch (\Throwable $refused) {
			$this->rollBack();
			throw $refused;
		}

		$this->wrapper->performDropTableCalls();
	}//end apply()

	/**
	 * Run the statements in order.
	 *
	 * @param list<string> $statements The statements.
	 *
	 * @return void
	 */
	private function run(array $statements): void {
		foreach ($statements as $statement) {
			$this->connection->executeStatement($statement);
		}
	}//end run()

	/**
	 * Close the transaction of a refused step.
	 *
	 * @return void
	 */
	private function rollBack(): void {
		try {
			$this->connection->rollBack();
		} catch (\Throwable $e) {
			// The refusal of the statement is what the caller is told; a connection that cannot roll back adds nothing to it.
			unset($e);
		}
	}//end rollBack()

	/**
	 * The statements that turn the tables as they were into the tables as the step left them.
	 *
	 * Without the drop of a table the step dropped: that goes through the wrapper, as Nextcloud does it.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	public function statements(): array {
		$manager = $this->connection->createSchemaManager();
		$platform = $this->connection->getDatabasePlatform();
		$after = new Schema(tables: $this->schema->touched(), schemaConfig: $manager->createSchemaConfig());
		$this->asNextcloudStores(schema: $after, withComments: ($platform instanceof SqlitePlatform) === false);

		$before = $this->schema->before();
		foreach ($before->getTables() as $table) {
			if ($after->hasTable($table->getName()) === false) {
				$before->dropTable($table->getName());
			}
		}

		return array_values($platform->getAlterSchemaSQL($manager->createComparator()->compareSchemas($before, $after)));
	}//end statements()

	/**
	 * Adjust the step's columns the way Nextcloud's migrators do before they compare.
	 *
	 * A string column longer than 4000 becomes text, on every database alike
	 * (Migrator). A column comment is dropped where the database has none to
	 * store, which is SQLite (SQLiteMigrator): kept, it would differ from the
	 * table read back on every later step.
	 *
	 * @param Schema $schema       The tables the step left.
	 * @param bool   $withComments Whether the database stores column comments.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Doctrine's type registry has no other entry
	 */
	private function asNextcloudStores(Schema $schema, bool $withComments): void {
		foreach ($schema->getTables() as $table) {
			foreach ($table->getColumns() as $column) {
				if ($column->getType() instanceof StringType && $column->getLength() > 4000) {
					$column->setType(Type::getType('text'));
					$column->setLength(null);
				}

				if ($withComments === false && $column->getComment() !== null) {
					$column->setComment(null);
				}
			}
		}
	}//end asNextcloudStores()
}//end class
