<?php

/**
 * No migration names a column with a word MariaDB or MySQL reserves.
 *
 * Doctrine quotes a column name only when its own keyword list for the
 * platform has the word, and those lists are incomplete: `row_number` is
 * reserved on MariaDB but missing from Doctrine's MariaDB list, so the import
 * preview migration failed on every MariaDB install (#4909). CI does not run
 * the migrations on MariaDB, so this test checks every column the migrations
 * define against the words each supported MariaDB and MySQL series reserves,
 * whatever Doctrine version runs the test.
 *
 * The lists come from the upstream server sources, one per series
 * (ReservedWordSource::SOURCES), so a new point release or a word a coming
 * series reserves is picked up without an edit. The snapshots in fixtures/ back
 * them up: a series whose download fails is checked against its snapshot, and
 * the test never needs network. A word upstream starts to reserve that a
 * migration already uses fails this test; rename the column in a new migration
 * or, for a column that is already released, add the word to ALREADY_IN_USE.
 * `php fixtures/update-reserved-words.php` refreshes the snapshots.
 *
 * It needs a Nextcloud server's classes for the migrated schema, like the
 * other MigratedSqliteDatabase tests, so it runs in CI and not in a bare clone.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCA\OpenRegister\Tests\Support\ReservedWordSource;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class ReservedColumnNamesTest extends TestCase {

	/**
	 * Reserved words already used as a column name before this test existed.
	 *
	 * Doctrine quotes each of them on every engine where it is reserved
	 * (`groups` is reserved on MySQL 8 only, `offset` on MariaDB only), so
	 * the migrations run. Renaming them would touch released tables for no
	 * failure; a new column takes a name that is not reserved.
	 */
	private const ALREADY_IN_USE = ['cursor', 'groups', 'limit', 'offset', 'schema', 'schemas', 'trigger'];

	/**
	 * The words one series reserves, lower case.
	 *
	 * @param string $engine The engine, a key of ReservedWordSource::SOURCES.
	 * @param string $series The series.
	 *
	 * @return string[]
	 */
	private static function reservedWords(string $engine, string $series): array {
		$words = ReservedWordSource::words(engine: $engine, series: $series);
		self::assertGreaterThan(200, count($words), 'The '.$engine.' '.$series.' list is nearly empty, so nothing would be checked');

		return $words;
	}//end reservedWords()

	/**
	 * Every word any supported series of either engine reserves, as a lookup.
	 *
	 * @return array<string, true>
	 */
	private static function reserved(): array {
		$words = [];
		foreach (ReservedWordSource::SOURCES as $engine => $source) {
			foreach ($source['series'] as $series) {
				$words = array_merge($words, self::reservedWords(engine: $engine, series: $series));
			}
		}

		return array_fill_keys($words, true);
	}//end reserved()

	/**
	 * No column the migrations define is a reserved word, apart from the ones already in use.
	 *
	 * @return void
	 */
	public function testNoMigrationColumnIsAReservedWord(): void {
		$reserved  = self::reserved();
		$offenders = [];
		$columns   = 0;
		foreach (MigratedSqliteDatabase::migratedSchema(test: $this)->getTables() as $table) {
			foreach ($table->getColumns() as $column) {
				$columns++;
				$name = strtolower(trim($column->getName(), '"`'));
				if (isset($reserved[$name]) === true && in_array($name, self::ALREADY_IN_USE, true) === false) {
					$offenders[] = $table->getName().'.'.$name;
				}
			}
		}

		$this->assertGreaterThan(100, $columns, 'The migrated schema came back nearly empty, so nothing was checked');
		$this->assertSame(
			[],
			$offenders,
			'These columns use a word MariaDB or MySQL reserves; Doctrine may not quote it there. Pick another name. '
			.'Lists used: '.json_encode(ReservedWordSource::origins())
		);
	}//end testNoMigrationColumnIsAReservedWord()

	/**
	 * The lists hold the words they must, so the check above can catch the word that broke MariaDB.
	 *
	 * ROW_NUMBER is reserved on MariaDB 11.0 to 11.3 and MySQL 8, SELECT on every series,
	 * and WINDOW on MySQL 8 (MariaDB lets it through as a column name).
	 *
	 * @return void
	 */
	public function testTheListsHoldTheWordsThatMustBeReserved(): void {
		$this->assertContains('row_number', self::reservedWords(engine: 'mariadb', series: '11.3'));
		$this->assertContains('row_number', self::reservedWords(engine: 'mysql', series: '8.0'));
		$this->assertContains('window', self::reservedWords(engine: 'mysql', series: '8.0'));
		$this->assertNotContains('row_number', self::ALREADY_IN_USE);

		foreach (ReservedWordSource::SOURCES as $engine => $source) {
			foreach ($source['series'] as $series) {
				$this->assertContains('select', self::reservedWords(engine: $engine, series: $series), $engine.' '.$series);
			}
		}
	}//end testTheListsHoldTheWordsThatMustBeReserved()

	/**
	 * Every word in the allowance is still a column and still reserved, so the allowance cannot grow stale.
	 *
	 * @return void
	 */
	public function testTheAllowanceOnlyHoldsReservedColumnsInUse(): void {
		$reserved = self::reserved();
		$names    = [];
		foreach (MigratedSqliteDatabase::migratedSchema(test: $this)->getTables() as $table) {
			foreach ($table->getColumns() as $column) {
				$names[strtolower(trim($column->getName(), '"`'))] = true;
			}
		}

		foreach (self::ALREADY_IN_USE as $word) {
			$this->assertArrayHasKey($word, $reserved, $word.' is not reserved; drop it from ALREADY_IN_USE');
			$this->assertArrayHasKey($word, $names, $word.' is no longer a column; drop it from ALREADY_IN_USE');
		}
	}//end testTheAllowanceOnlyHoldsReservedColumnsInUse()
}//end class
