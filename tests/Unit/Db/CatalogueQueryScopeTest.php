<?php

/**
 * CatalogueQueryScopeTest: every query on the database catalogue names the schema it reads.
 *
 * `information_schema` describes every database on the server, not the one the
 * connection is in. A query on it without `table_schema` answers for all of
 * them: on a server that holds a second Nextcloud, OpenRegister listed that
 * instance's magic tables as its own, swept them on every upgrade and failed on
 * each (measured: 38 tables of a neighbouring instance in every sweep, and
 * 40,202 "Error searching table" log entries from one background job once a
 * neighbour held a thousand). A column lookup by table name alone answers with
 * the columns of every same-named table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-catalogue-query-reads-the-instances-own-database-only
 */
class CatalogueQueryScopeTest extends TestCase {

	/**
	 * How many lines after the catalogue view the schema predicate may follow in the same statement.
	 */
	private const STATEMENT_LINES = 6;

	/**
	 * Files that query a database other than the instance's own, on purpose.
	 */
	private const OTHER_DATABASES = ['lib/Service/Dbal/'];

	/**
	 * Every code line that reads a catalogue view, with the lines of its statement.
	 *
	 * @return array<string, string> The statement text by `file:line`.
	 */
	private function catalogueStatements(): array {
		$lib = dirname(__DIR__, 3) . '/lib';
		$statements = [];

		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
			$path = 'lib/' . ltrim(substr((string) $file, strlen($lib)), '/');
			if (str_ends_with($path, '.php') === false || str_starts_with($path, 'lib/Migration/Version') === true) {
				continue;
			}

			foreach (self::OTHER_DATABASES as $excluded) {
				if (str_starts_with($path, $excluded) === true) {
					continue 2;
				}
			}

			$lines = (array) file((string) $file, FILE_IGNORE_NEW_LINES);
			foreach ($lines as $index => $line) {
				$code = ltrim((string) $line);
				if (str_starts_with($code, '*') === true || str_starts_with($code, '//') === true || str_starts_with($code, '/*') === true) {
					continue;
				}

				if (preg_match('/\bFROM\s+information_schema\.(tables|columns|statistics|check_constraints)\b/i', $code) !== 1) {
					continue;
				}

				$statements[$path . ':' . ($index + 1)] = implode("\n", array_slice($lines, $index, self::STATEMENT_LINES));
			}
		}//end foreach

		return $statements;
	}//end catalogueStatements()

	public function testTheScanFindsTheCatalogueQueries(): void {
		$this->assertGreaterThan(10, count($this->catalogueStatements()), 'the scan found implausibly few catalogue queries');
	}//end testTheScanFindsTheCatalogueQueries()

	public function testEveryCatalogueQueryNamesItsSchema(): void {
		$offenders = [];
		foreach ($this->catalogueStatements() as $where => $statement) {
			if (preg_match('/table_schema|constraint_schema|CatalogueScope|currentSchemaFunction/i', $statement) !== 1) {
				$offenders[] = $where;
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"a query on information_schema must be limited to the instance's own database\n"
			. "(AND table_schema = DATABASE() / current_schema(), see CatalogueScope):\n" . implode("\n", $offenders)
		);
	}//end testEveryCatalogueQueryNamesItsSchema()
}//end class
