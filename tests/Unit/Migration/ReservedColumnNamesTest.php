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
 * (ReservedWordSource::SOURCES): the branch head is read live, so a word a new
 * point release reserves is picked up without an edit. A branch head is only the
 * newest point release, so the snapshots in fixtures/ hold the union over the head
 * and every release tag of the series; they are always merged into the live list
 * and are the only source for the older point releases. A word upstream stops
 * reserving therefore stays checked until the snapshot is regenerated, and when a
 * download fails the snapshot alone is used: the test never needs network.
 * A word upstream starts to reserve that a migration already uses fails this
 * test; rename the column in a new migration
 * or, for a column that is already released, add the word to ALREADY_IN_USE.
 * The development branches (`main`, `trunk`; ReservedWordSource::SOURCES marks
 * them) are not a gate: a word only they reserve is reported as an incomplete
 * test naming the columns, engine and series, and the run stays green.
 * `php fixtures/update-reserved-words.php` refreshes the snapshots.
 *
 * Limit: only the columns the Doctrine schema closures define are checked,
 * because MigratedSqliteDatabase::migratedSchema() runs `changeSchema()` and
 * nothing else. A column created by raw SQL, or in preSchemaChange and
 * postSchemaChange, is invisible here and its name needs a manual look against
 * the lists. Today that is Version1Date20260322120000 (`ALTER TABLE ... ADD COLUMN`
 * retention_nominatie, retention_status and retention_datum, MariaDB only) and
 * Version1Date20260706100000 (`CREATE TABLE` with vector_id and embedding,
 * PostgreSQL only); none of those names is reserved. Scanning the migration
 * sources for them would not be robust: the first builds the name from an array
 * key interpolated into the statement, the second spreads it over concatenated strings.
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
	 *
	 * The allowance is one list for both engines and every series, which would
	 * hide the #4909 failure if an engine started to reserve one of these words
	 * and Doctrine did not know it there. That is why
	 * testDoctrineQuotesEveryAllowedWordWhereItIsReserved() asserts the premise
	 * per engine: the allowance is only as wide as Doctrine's own keyword lists.
	 */
	private const ALREADY_IN_USE = ['cursor', 'groups', 'limit', 'offset', 'schema', 'schemas', 'trigger'];

	/**
	 * Doctrine's keyword lists per engine; a column is quoted when the platform's list has its name.
	 *
	 * Every list a supported Nextcloud (32 to 35, all on doctrine/dbal 3.10) can
	 * pick for a MariaDB or MySQL 8 server. The classes a bare host lacks are
	 * skipped; the test is skipped when none of an engine's classes loads.
	 */
	private const DOCTRINE_KEYWORDS = [
		'mariadb' => [
			'Doctrine\DBAL\Platforms\Keywords\MariaDBKeywords',
			'Doctrine\DBAL\Platforms\Keywords\MariaDb102Keywords',
			'Doctrine\DBAL\Platforms\Keywords\MariaDb117Keywords',
		],
		'mysql'   => [
			'Doctrine\DBAL\Platforms\Keywords\MySQL80Keywords',
			'Doctrine\DBAL\Platforms\Keywords\MySQL84Keywords',
		],
	];

	/**
	 * Where each list came from: the series per origin, and why the download failed if one did.
	 *
	 * @return string
	 */
	private static function originReport(): string {
		$by = [];
		foreach (ReservedWordSource::origins() as $series => $origin) {
			$by[$origin][] = $series;
		}

		$parts = [];
		foreach ($by as $origin => $series) {
			$parts[] = $origin.': '.implode(', ', $series);
		}

		$failure = ReservedWordSource::downloadFailure();

		return implode('; ', $parts).(($failure === null) ? '' : '. Download failed: '.$failure);
	}//end originReport()

	/**
	 * Says on STDERR where the lists came from, so a snapshot-only run is visible while green.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		if (ReservedWordSource::origins() !== [] && defined('STDERR') === true) {
			fwrite(STDERR, PHP_EOL.'ReservedColumnNamesTest word lists - '.self::originReport().PHP_EOL);
		}
	}//end tearDownAfterClass()

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
	 * Which series reserve each word, for the released series or for the development branches.
	 *
	 * @param bool $development True for the development branches, false for the released series.
	 *
	 * @return array<string, string[]> Word => the `<engine> <series>` entries that reserve it.
	 */
	private static function reservers(bool $development): array {
		$by = [];
		foreach (ReservedWordSource::SOURCES as $engine => $source) {
			foreach ($source['series'] as $series) {
				if (ReservedWordSource::isDevelopment(engine: $engine, series: $series) !== $development) {
					continue;
				}

				foreach (self::reservedWords(engine: $engine, series: $series) as $word) {
					$by[$word][] = $engine.' '.$series;
				}
			}
		}

		return $by;
	}//end reservers()

	/**
	 * Every column the migrations define, lower case, as `<table>.<column>` entries per name.
	 *
	 * @param TestCase $test The running test, for the schema's database setup.
	 *
	 * @return array<string, string[]> Column name => the tables that have it.
	 */
	private static function migratedColumns(TestCase $test): array {
		$columns = [];
		foreach (MigratedSqliteDatabase::migratedSchema(test: $test)->getTables() as $table) {
			foreach ($table->getColumns() as $column) {
				$columns[strtolower(trim($column->getName(), '"`'))][] = $table->getName();
			}
		}

		self::assertGreaterThan(100, count($columns), 'The migrated schema came back nearly empty, so nothing was checked');

		return $columns;
	}//end migratedColumns()

	/**
	 * The columns whose name is in a reserved-word map and not in the allowance.
	 *
	 * @param array<string, string[]> $columns  Column name => tables, from migratedColumns().
	 * @param array<string, string[]> $reserved Word => the series that reserve it.
	 *
	 * @return string[] Entries like `table.column (mariadb 10.6, mysql 8.0)`.
	 */
	private static function offenders(array $columns, array $reserved): array {
		$offenders = [];
		foreach ($columns as $name => $tables) {
			if (isset($reserved[$name]) === false || in_array($name, self::ALREADY_IN_USE, true) === true) {
				continue;
			}

			foreach ($tables as $table) {
				$offenders[] = $table.'.'.$name.' ('.implode(', ', $reserved[$name]).')';
			}
		}

		sort($offenders);

		return $offenders;
	}//end offenders()

	/**
	 * No column the migrations define is a word a released series reserves, apart from the ones already in use.
	 *
	 * @return void
	 */
	public function testNoMigrationColumnIsAReservedWord(): void {
		$this->assertSame(
			[],
			self::offenders(columns: self::migratedColumns(test: $this), reserved: self::reservers(development: false)),
			'These columns use a word the listed MariaDB or MySQL series reserve; Doctrine may not quote it there. '
			.'Pick another name. Lists used - '.self::originReport()
		);
	}//end testNoMigrationColumnIsAReservedWord()

	/**
	 * A word only a development branch reserves is reported, not failed: it is a heads-up for the next release.
	 *
	 * The suite stays green (nothing in phpunit.xml or the CI workflow sets failOnIncomplete);
	 * the report names each column with the engine and series that reserve its word.
	 *
	 * @return void
	 */
	public function testNoMigrationColumnIsReservedOnlyOnADevelopmentBranch(): void {
		$released = self::reservers(development: false);
		$coming   = array_diff_key(self::reservers(development: true), $released);
		$notices  = self::offenders(columns: self::migratedColumns(test: $this), reserved: $coming);
		if ($notices !== []) {
			$this->markTestIncomplete(
				'Heads-up, not a failure: these columns use a word only a development branch reserves so far, '
				.'so a coming MariaDB or MySQL series may need a rename migration: '.implode('; ', $notices)
			);
		}

		$this->addToAssertionCount(1);
	}//end testNoMigrationColumnIsReservedOnlyOnADevelopmentBranch()

	/**
	 * The lists hold the words they must, so the check above can catch the word that broke MariaDB.
	 *
	 * ROW_NUMBER is reserved on MariaDB from 10.7 on and on MySQL 8, SELECT on every series,
	 * and WINDOW on MySQL 8 (MariaDB lets it through as a column name).
	 *
	 * The MariaDB 10.11 and 11.4 branch heads stopped listing ROW_NUMBER while every
	 * released 10.11.x and 11.4.x still reserves it, so for those series (and 11.8,
	 * whose releases reserve it as well) only the snapshot's release history
	 * holds the word. A snapshot regenerated from branch heads alone would fail here.
	 *
	 * @return void
	 */
	public function testTheListsHoldTheWordsThatMustBeReserved(): void {
		foreach (['10.11', '11.3', '11.4', '11.8'] as $series) {
			$this->assertContains('row_number', self::reservedWords(engine: 'mariadb', series: $series), 'mariadb '.$series);
		}

		foreach (['8.0', '8.4'] as $series) {
			$this->assertContains('row_number', self::reservedWords(engine: 'mysql', series: $series), 'mysql '.$series);
		}

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
		$reserved = (self::reservers(development: false) + self::reservers(development: true));
		$names    = self::migratedColumns(test: $this);

		foreach (self::ALREADY_IN_USE as $word) {
			$this->assertArrayHasKey($word, $reserved, $word.' is not reserved; drop it from ALREADY_IN_USE');
			$this->assertArrayHasKey($word, $names, $word.' is no longer a column; drop it from ALREADY_IN_USE');
		}
	}//end testTheAllowanceOnlyHoldsReservedColumnsInUse()

	/**
	 * The allowance's premise holds: Doctrine's keyword list for an engine has every allowed word that engine reserves.
	 *
	 * Doctrine quotes a column only when its list has the name, so an allowed word that
	 * an engine reserves and Doctrine does not list would be created unquoted there.
	 *
	 * @return void
	 */
	public function testDoctrineQuotesEveryAllowedWordWhereItIsReserved(): void {
		$lists = [];
		foreach (self::DOCTRINE_KEYWORDS as $engine => $classes) {
			foreach ($classes as $class) {
				if (class_exists($class) === true) {
					$lists[$engine][$class] = new $class();
				}
			}

			if (isset($lists[$engine]) === false) {
				$this->markTestSkipped('Doctrine\'s '.$engine.' keyword classes are not loadable here ('.implode(', ', $classes).'), so the allowance cannot be checked');
			}
		}

		$reserved = array_merge_recursive(self::reservers(development: false), self::reservers(development: true));
		$missing  = [];
		foreach (self::ALREADY_IN_USE as $word) {
			foreach (($reserved[$word] ?? []) as $entry) {
				$engine = explode(' ', $entry)[0];
				foreach ($lists[$engine] as $class => $list) {
					if ($list->isKeyword($word) === false) {
						$missing[$word.' ('.$entry.')'][] = substr($class, (strrpos($class, '\\') + 1));
					}
				}
			}
		}

		$this->assertSame(
			[],
			$missing,
			'These allowed words are reserved by the series shown, but the Doctrine list named is without them, '
			.'so Doctrine would not quote them there: rename the column instead of allowing it.'
		);
	}//end testDoctrineQuotesEveryAllowedWordWhereItIsReserved()
}//end class
