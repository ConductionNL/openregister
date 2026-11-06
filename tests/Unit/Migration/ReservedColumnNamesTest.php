<?php

/**
 * No migration names a column with a word MariaDB or MySQL reserves.
 *
 * Doctrine quotes a column name only when its own keyword list for the
 * platform has the word, and those lists are incomplete: `row_number` is
 * reserved on MariaDB but missing from Doctrine's MariaDB list, so the import
 * preview migration failed on every MariaDB install (#4909). CI does not run
 * the migrations on MariaDB, so this test checks every column the migrations
 * define against the reserved words measured on MariaDB 11.3 and MySQL 8.0
 * (fixtures/), whatever Doctrine version runs the test.
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
	 * The words one fixture lists, lower case.
	 *
	 * @param string $file The fixture file name.
	 *
	 * @return string[]
	 */
	private static function reservedWords(string $file): array {
		$lines = file(__DIR__.'/fixtures/'.$file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		self::assertIsArray($lines, 'Fixture '.$file.' could not be read');

		return array_values(
			array_filter(
				array_map(static fn (string $line): string => strtolower(trim($line)), $lines),
				static fn (string $word): bool => $word !== '' && str_starts_with($word, '#') === false
			)
		);
	}//end reservedWords()

	/**
	 * Every reserved word on either engine, as a lookup.
	 *
	 * @return array<string, true>
	 */
	private static function reserved(): array {
		$words = array_merge(
			self::reservedWords(file: 'reserved-words-mariadb-11.3.txt'),
			self::reservedWords(file: 'reserved-words-mysql-8.0.txt')
		);

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
			'These columns use a word MariaDB or MySQL reserves; Doctrine may not quote it there. Pick another name.'
		);
	}//end testNoMigrationColumnIsAReservedWord()

	/**
	 * The fixtures still hold the word that broke MariaDB, so the check above can catch it.
	 *
	 * @return void
	 */
	public function testTheWordThatBrokeMariaDbIsReserved(): void {
		$this->assertContains('row_number', self::reservedWords(file: 'reserved-words-mariadb-11.3.txt'));
		$this->assertNotContains('row_number', self::ALREADY_IN_USE);
	}//end testTheWordThatBrokeMariaDbIsReserved()

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
