<?php

/**
 * TargetedSchema: a database schema that reads only the tables a migration asks for.
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

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;

/**
 * A Doctrine schema that introspects a table the first time it is named.
 *
 * Nextcloud hands a migration step the whole database: every table, column,
 * index and constraint, read three times per step. A step names the two or
 * three tables it changes, so this schema reads those and nothing else: the
 * list of table names once, then one table at a time.
 *
 * It keeps each table as the database held it, so the change a step made can
 * be worked out from the tables it touched alone ({@see before()} against
 * {@see touched()}).
 *
 * A step that asks for every table gets every table, read the slow way.
 *
 * The overrides carry no type declarations because Doctrine DBAL 3's do not.
 *
 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
 *
 * phpcs:disable Squiz.Commenting.FunctionComment.TypeHintMissing,Squiz.Commenting.FunctionComment.ScalarTypeHintMissing
 */
final class TargetedSchema extends Schema {

	/**
	 * Each table asked for, as the database held it: null when it did not exist. Keyed by lower-cased name.
	 *
	 * @var array<string, Table|null>
	 */
	private array $asFound = [];

	/**
	 * The names of the tables in the database, lower-cased, as keys; null until first needed.
	 *
	 * @var array<string, true>|null
	 */
	private ?array $existing = null;

	/**
	 * Constructor.
	 *
	 * @param AbstractSchemaManager $manager The schema manager of the connection.
	 */
	public function __construct(private readonly AbstractSchemaManager $manager) {
		parent::__construct(schemaConfig: $manager->createSchemaConfig());
	}//end __construct()

	/**
	 * Whether the database or this step has the table.
	 *
	 * @param string $name The full table name.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	public function hasTable($name) {
		$this->read(name: (string)$name);

		return parent::hasTable(name: $name);
	}//end hasTable()

	/**
	 * The table, read from the database when this is the first time it is named.
	 *
	 * @param string $name The full table name.
	 *
	 * @return Table
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	public function getTable($name) {
		$this->read(name: (string)$name);

		return parent::getTable(name: $name);
	}//end getTable()

	/**
	 * Create a table; refused, as by a full schema, when the database already has it.
	 *
	 * @param string $name The full table name.
	 *
	 * @return Table
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	public function createTable($name) {
		$this->read(name: (string)$name);

		return parent::createTable(name: $name);
	}//end createTable()

	/**
	 * Drop a table from the schema.
	 *
	 * @param string $name The full table name.
	 *
	 * @return $this
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	public function dropTable($name) {
		$this->read(name: (string)$name);
		parent::dropTable(name: $name);

		return $this;
	}//end dropTable()

	/**
	 * Every table of the database: a step that asks for all of them is given all of them.
	 *
	 * @return Table[]
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	public function getTables() {
		$this->readAll();

		return parent::getTables();
	}//end getTables()

	/**
	 * The name of every table of the database.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	public function getTableNames() {
		$this->readAll();

		return parent::getTableNames();
	}//end getTableNames()

	/**
	 * The tables this step named that the database held, as it held them.
	 *
	 * @return Schema
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	public function before(): Schema {
		return new Schema(tables: array_values(array_filter($this->asFound)), schemaConfig: $this->manager->createSchemaConfig());
	}//end before()

	/**
	 * The tables this step named or created, as the step left them.
	 *
	 * @return Table[]
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	public function touched(): array {
		return parent::getTables();
	}//end touched()

	/**
	 * Read a table from the database unless it was already asked for.
	 *
	 * @param string $name The full table name.
	 *
	 * @return void
	 */
	private function read(string $name): void {
		$key = strtolower($name);
		if (array_key_exists($key, $this->asFound) === true) {
			return;
		}

		$this->asFound[$key] = null;
		if (isset($this->existingTables()[$key]) === false) {
			return;
		}

		$table = $this->manager->introspectTable($name);
		$this->asFound[$key] = clone $table;
		$this->_addTable(table: $table);
	}//end read()

	/**
	 * Read every table not read yet.
	 *
	 * @return void
	 */
	private function readAll(): void {
		foreach ($this->manager->listTableNames() as $name) {
			$this->read(name: $name);
		}
	}//end readAll()

	/**
	 * The names of the tables in the database, read once.
	 *
	 * @return array<string, true>
	 */
	private function existingTables(): array {
		if ($this->existing === null) {
			$this->existing = array_fill_keys(array_map('strtolower', $this->manager->listTableNames()), true);
		}

		return $this->existing;
	}//end existingTables()
}//end class
