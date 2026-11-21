<?php

/**
 * TargetedMigrationStep: a migration step that, on an upgrade, reads only the tables it changes.
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

use Closure;
use OC\DB\Connection;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Server;
use ReflectionFunction;

/**
 * Base class of OpenRegister's migration steps.
 *
 * WHY. On an upgrade Nextcloud runs each step on its own and reads the whole
 * database schema for it three times: once for the schema it hands the step,
 * once to check names, once to compare. That read covers every table of every
 * app, and an instance holds one magic table per register and schema. Measured
 * with 1,067 of them on MariaDB: four minutes a read, so twelve a step, and an
 * upgrade from the last stable release ran 78 steps (#10612). The same reads
 * hold three copies of the schema in memory at once (#3776).
 *
 * WHAT. On that path a step extending this class is handed a schema that reads
 * only the tables the step names ({@see TargetedSchema}), and the difference
 * is applied here rather than by Nextcloud, which is then told there is
 * nothing left to do.
 *
 * WHEN NOT. A fresh install hands all steps one shared schema and applies the
 * sum once, and the schema check replays the steps against a schema it built
 * itself. Both keep working as they did: only a step Nextcloud's
 * MigrationService runs on its own is targeted. A test that calls a hook with
 * its own closure gets that closure.
 *
 * A step implements the three `…Targeted…` hooks below in place of
 * `preSchemaChange`, `changeSchema` and `postSchemaChange`; their arguments
 * and return values are the same.
 *
 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
 */
abstract class TargetedMigrationStep extends SimpleMigrationStep {

	/**
	 * The config.php key that, set to false, leaves every step to Nextcloud's own path.
	 */
	public const CONFIG_KEY = 'openregister.targeted_migrations';

	/**
	 * Whether Nextcloud is running this step on its own: only then does it call preSchemaChange().
	 *
	 * @var boolean
	 */
	private bool $runsOnItsOwn = false;

	/**
	 * Before the schema change.
	 *
	 * @param IOutput $output        The output.
	 * @param Closure $schemaClosure Returns the schema.
	 * @param array   $options       The options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	final public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->runsOnItsOwn = true;
		$this->beforeTargetedSchemaChange(output: $output, schemaClosure: $this->readOnly(schemaClosure: $schemaClosure), options: $options);
	}//end preSchemaChange()

	/**
	 * The schema change.
	 *
	 * @param IOutput $output        The output.
	 * @param Closure $schemaClosure Returns the schema.
	 * @param array   $options       The options.
	 *
	 * @return ISchemaWrapper|null The schema Nextcloud still has to apply; null when there is nothing left to apply.
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	final public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$runsOnItsOwn = $this->runsOnItsOwn;
		$this->runsOnItsOwn = false;
		if ($runsOnItsOwn === false || $this->isNextclouds(schemaClosure: $schemaClosure) === false) {
			return $this->changeTargetedSchema(output: $output, schemaClosure: $schemaClosure, options: $options);
		}

		$change = new TargetedSchemaChange(connection: $this->connection());
		$wrapper = $change->wrapper();
		$schema = $this->changeTargetedSchema(output: $output, schemaClosure: static fn (): ISchemaWrapper => $wrapper, options: $options);
		if ($schema !== $wrapper) {
			// Null, or a schema the step got elsewhere: Nextcloud's to handle, as before.
			return $schema;
		}

		$change->apply();

		return null;
	}//end changeSchema()

	/**
	 * After the schema change.
	 *
	 * @param IOutput $output        The output.
	 * @param Closure $schemaClosure Returns the schema.
	 * @param array   $options       The options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	final public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->afterTargetedSchemaChange(output: $output, schemaClosure: $this->readOnly(schemaClosure: $schemaClosure), options: $options);
	}//end postSchemaChange()

	/**
	 * The step's work before its schema change; the schema is for reading only.
	 *
	 * @param IOutput $output        The output.
	 * @param Closure $schemaClosure Returns the schema.
	 * @param array   $options       The options.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) A hook: the step that overrides it uses them
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	protected function beforeTargetedSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}//end beforeTargetedSchemaChange()

	/**
	 * The step's schema change: return the schema it got from the closure, as changeSchema() would.
	 *
	 * @param IOutput $output        The output.
	 * @param Closure $schemaClosure Returns the schema.
	 * @param array   $options       The options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) A hook: the step that overrides it uses them
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	protected function changeTargetedSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		return null;
	}//end changeTargetedSchema()

	/**
	 * The step's work after its schema change; the schema is for reading only.
	 *
	 * @param IOutput $output        The output.
	 * @param Closure $schemaClosure Returns the schema.
	 * @param array   $options       The options.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) A hook: the step that overrides it uses them
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-migration-step-reads-only-the-tables-it-changes
	 */
	protected function afterTargetedSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}//end afterTargetedSchemaChange()

	/**
	 * The closure a read-only hook is handed: targeted when the one given is Nextcloud's, else the one given.
	 *
	 * @param Closure $schemaClosure The closure the hook was called with.
	 *
	 * @return Closure
	 */
	private function readOnly(Closure $schemaClosure): Closure {
		if ($this->isNextclouds(schemaClosure: $schemaClosure) === false) {
			return $schemaClosure;
		}

		return fn (): ISchemaWrapper => (new TargetedSchemaChange(connection: $this->connection()))->wrapper();
	}//end readOnly()

	/**
	 * Whether a schema closure is the one Nextcloud's MigrationService makes, which reads the whole database.
	 *
	 * Always false when `openregister.targeted_migrations` is set to false in
	 * config.php: the way back to Nextcloud's own path, should a step ever
	 * come out differently on this one.
	 *
	 * @param Closure $schemaClosure The closure a hook was called with.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OCP\Server is Nextcloud's service locator
	 */
	private function isNextclouds(Closure $schemaClosure): bool {
		if (is_a((new ReflectionFunction($schemaClosure))->getClosureThis(), 'OC\DB\MigrationService') === false) {
			return false;
		}

		return Server::get(IConfig::class)->getSystemValueBool(self::CONFIG_KEY, true);
	}//end isNextclouds()

	/**
	 * Nextcloud's database connection.
	 *
	 * Located, not injected: the steps have constructors of their own, and
	 * Nextcloud builds a step without arguments when it cannot resolve them.
	 *
	 * @return Connection
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OCP\Server is Nextcloud's service locator
	 */
	private function connection(): Connection {
		return Server::get(Connection::class);
	}//end connection()
}//end class
