<?php

/**
 * Source-level and behavioural invariants for migration schema reuse.
 *
 * `OC\DB\MigrationService::migrateSchemaOnly()` — the path `occ app:enable`
 * and app upgrades run — threads a single schema snapshot through every
 * migration in one run:
 *
 * ```php
 * $toSchema = null;
 * foreach ($toBeExecuted as $version) {
 *     $toSchema = $instance->changeSchema($output, function () use ($toSchema) {
 *         return $toSchema ?: new SchemaWrapper($this->connection);
 *     }, $options) ?: $toSchema;
 * }
 * ```
 *
 * The reuse only happens while each step hands the snapshot back. A step that
 * returns `null` leaves `$toSchema` unset, so the next step's closure
 * introspects the WHOLE database again — every table, column, index and
 * foreign key, not just this app's. Doctrine's schema graph is cyclic, so
 * those snapshots are not reclaimed promptly and the run accumulates one per
 * null-returning step until PHP's memory_limit is hit.
 *
 * That is not theoretical for OpenRegister: an upgrade of an instance carrying
 * many dynamic `oc_openregister_table_*` object tables (each ~20 indexes:
 * GIN + trigram + partial + facet) makes every re-introspection expensive, and
 * `occ app:enable openregister` died of memory exhaustion at the 512M default.
 * Returning `$schema` from the idempotency guards instead of `null` keeps the
 * whole run on one snapshot (see ConductionNL/openregister#3776).
 *
 * The trap is that `return null;` looks tidier and is what most Nextcloud
 * migration examples show, so it invites being "cleaned up" back. This suite
 * makes that revert fail.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://openregister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\OpenRegister\Migration\Version1Date20260812100000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Tests that every migration step hands its schema snapshot back.
 */
class SchemaReuseHygieneTest extends TestCase {

	/**
	 * Absolute path to the app's lib/Migration/ directory.
	 *
	 * @return string The migration path.
	 */
	private function migrationPath(): string {
		return dirname(__DIR__, 3) . '/lib/Migration';
	}//end migrationPath()

	/**
	 * Every migration file, as absolute paths.
	 *
	 * @return string[] The file paths.
	 */
	private function migrationFiles(): array {
		$files = glob($this->migrationPath() . '/Version*.php');
		if ($files === false) {
			return [];
		}

		sort($files);

		return $files;
	}//end migrationFiles()

	/**
	 * The body of a file's changeSchema() method, as numbered lines.
	 *
	 * Keyed by 1-based line number in the original file so offenders can be
	 * reported at a location a reader can jump to.
	 *
	 * @param string $file Absolute path to the migration file.
	 *
	 * @return array<int,string> The method body lines.
	 */
	private function changeSchemaBody(string $file): array {
		$lines = (array) file($file, FILE_IGNORE_NEW_LINES);
		$body = [];
		$inside = false;

		foreach ($lines as $index => $line) {
			if (preg_match('/function\s+([A-Za-z_]\w*)\s*\(/', (string) $line, $matches) === 1) {
				$inside = ($matches[1] === 'changeSchema');
				continue;
			}

			if ($inside === true) {
				$body[($index + 1)] = (string) $line;
			}
		}

		return $body;
	}//end changeSchemaBody()

	/**
	 * The scan must actually reach the migration tree.
	 *
	 * Without this, a wrong path would make every assertion below pass
	 * vacuously — an empty scan and a clean one look identical.
	 *
	 * @return void
	 */
	public function testScanReachesTheMigrationTree(): void {
		$files = $this->migrationFiles();

		$this->assertGreaterThan(50, count($files), 'lib/Migration/ scan returned an implausibly small file list');

		$withGuards = 0;
		foreach ($files as $file) {
			$body = $this->changeSchemaBody($file);
			if ($body === []) {
				continue;
			}

			if (preg_match('/^\s*return \$schema;\s*$/m', implode("\n", $body)) === 1) {
				$withGuards++;
			}
		}

		// Nearly every step ends with `return $schema;`, so a zero here means
		// the body extraction is broken, not that the guards went away.
		$this->assertGreaterThan(0, $withGuards, 'no schema-returning guards found at all — the scan is not working');
	}//end testScanReachesTheMigrationTree()

	/**
	 * No changeSchema() returns null.
	 *
	 * A null return drops the shared snapshot and forces the next step to
	 * introspect the entire database again.
	 *
	 * @return void
	 */
	public function testNoChangeSchemaReturnsNull(): void {
		$offenders = [];

		foreach ($this->migrationFiles() as $file) {
			foreach ($this->changeSchemaBody($file) as $number => $line) {
				if (preg_match('/^\s*return\s+null\s*;\s*$/', $line) !== 1) {
					continue;
				}

				$offenders[] = 'lib/Migration/' . basename($file) . ':' . $number;
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"changeSchema() must return \$schema, not null — a null return drops the shared\n"
			. "schema snapshot and makes the next migration re-introspect the whole database:\n"
			. implode("\n", $offenders)
		);
	}//end testNoChangeSchemaReturnsNull()

	/**
	 * Every return inside changeSchema() hands back the schema.
	 *
	 * Broader than the null check: it also catches a guard that returns some
	 * other value, or a bare `return;`.
	 *
	 * @return void
	 */
	public function testEveryChangeSchemaReturnHandsBackTheSchema(): void {
		$offenders = [];

		foreach ($this->migrationFiles() as $file) {
			foreach ($this->changeSchemaBody($file) as $number => $line) {
				if (preg_match('/^\s*return\b(.*);\s*$/', $line, $matches) !== 1) {
					continue;
				}

				if (trim((string) $matches[1]) === '$schema') {
					continue;
				}

				$offenders[] = 'lib/Migration/' . basename($file) . ':' . $number . ' — ' . trim($line);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"every return in changeSchema() must be `return \$schema;`:\n" . implode("\n", $offenders)
		);
	}//end testEveryChangeSchemaReturnHandsBackTheSchema()

	/**
	 * A guard that fires because the table is absent returns the schema.
	 *
	 * `Version1Date20260812100000` short-circuits when `openregister_flows` is
	 * missing — one of the two early-exit shapes.
	 *
	 * @return void
	 */
	public function testGuardOnMissingTableReturnsTheSameSchemaInstance(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);

		$migration = new Version1Date20260812100000($this->createMock(IDBConnection::class));

		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			static fn (): ISchemaWrapper => $schema,
			['tablePrefix' => 'oc_']
		);

		$this->assertSame($schema, $result, 'the missing-table path must hand the snapshot back');
	}//end testGuardOnMissingTableReturnsTheSameSchemaInstance()

	/**
	 * A guard that fires because the column already exists returns the schema.
	 *
	 * `Version1Date20260812100000` short-circuits when `openregister_flows`
	 * already carries the `comment` column — the opposite guard direction, so
	 * both early-exit shapes are covered.
	 *
	 * @return void
	 */
	public function testGuardOnExistingColumnReturnsTheSameSchemaInstance(): void {
		$table = $this->createMock(Table::class);
		$table->method('hasColumn')->willReturn(true);
		$table->expects($this->never())->method('addColumn');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);

		$migration = new Version1Date20260812100000($this->createMock(IDBConnection::class));

		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			static fn (): ISchemaWrapper => $schema,
			['tablePrefix' => 'oc_']
		);

		$this->assertSame($schema, $result, 'the already-applied path must hand the snapshot back');
	}//end testGuardOnExistingColumnReturnsTheSameSchemaInstance()
}//end class
