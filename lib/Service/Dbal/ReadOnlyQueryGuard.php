<?php

/**
 * ReadOnlyQueryGuard — a conservative static guard for a query-backed schema's SQL.
 *
 * A query-backed virtual register runs an author's `config.query` as a derived
 * table against a connected database. The read path (design D-2) wraps it in a
 * read-only transaction, but a guard that refuses anything other than a single
 * read at schema save time catches the mistake where the author still has the
 * save in front of them, not the first reader. The guard is deliberately
 * conservative: it refuses by default and never tries to fully parse SQL
 * (design D-1).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dbal;

use InvalidArgumentException;

/**
 * Refuses any query-backed SQL that is not a single read (design D-1).
 *
 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
 */
class ReadOnlyQueryGuard {

	/**
	 * The keywords a read-only query may never contain outside a string literal.
	 *
	 * `UPDATE` covers both a bare `UPDATE` and the `FOR UPDATE` lock; the latter
	 * is listed in the design for clarity but needs no separate rule here.
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN = [
		'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'UPSERT', 'CREATE', 'ALTER',
		'DROP', 'TRUNCATE', 'GRANT', 'REVOKE', 'COPY', 'CALL', 'EXECUTE',
		'SET', 'LOCK', 'INTO',
	];

	/**
	 * Assert that `$sql` is a single read-only statement; throw otherwise.
	 *
	 * The guard strips comments and string literals first, so a forbidden
	 * keyword inside a literal (`SELECT 'UPDATE' AS x`) is accepted, then
	 * applies the design D-1 rules to what remains: one statement only, a
	 * `SELECT`/`WITH` head, no forbidden keyword, no bound placeholder.
	 *
	 * @param string $sql      The author's query.
	 * @param string $platform The DBAL platform name (`postgresql`, `mysql`, ...); comment syntax follows it.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the query is not a single read.
	 *
	 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
	 */
	public function assert(string $sql, string $platform): void {
		$bare = $this->withoutLiteralsAndComments(sql: $sql, platform: $platform);

		$body = rtrim($bare);
		if (strpos(rtrim($body, '; '), ';') !== false) {
			throw new InvalidArgumentException('A query-backed schema may contain only one statement.');
		}

		if (preg_match('/^\s*(\w+)/', $bare, $head) !== 1 || in_array(strtoupper($head[1]), ['SELECT', 'WITH'], true) === false) {
			throw new InvalidArgumentException('A query-backed schema must begin with SELECT or WITH.');
		}

		foreach (self::FORBIDDEN as $keyword) {
			if (preg_match('/\b' . $keyword . '\b/i', $bare) === 1) {
				throw new InvalidArgumentException(sprintf('A query-backed schema may not use "%s".', $keyword));
			}
		}

		if (preg_match('/\?/', $bare) === 1 || preg_match('/(?<!:):[a-zA-Z_]/', $bare) === 1) {
			throw new InvalidArgumentException('A query-backed schema may not bind placeholders; it runs as a derived table.');
		}
	}//end assert()

	/**
	 * The SQL with its comments and string literals replaced by single spaces.
	 *
	 * Replacing rather than deleting keeps token boundaries intact, so a block
	 * comment between two tokens does not fuse them into one word. Comment
	 * syntax is the union across platforms (line, block, and hash on
	 * MySQL/MariaDB); string literals are single-quoted with doubled-quote
	 * escaping, the one form every supported platform shares, plus
	 * double-quoted and back-quoted identifiers, which are stripped so a
	 * keyword used as an identifier is not mistaken for a statement.
	 *
	 * @param string $sql      The query.
	 * @param string $platform The DBAL platform name.
	 *
	 * @return string The query with comments and literals blanked out.
	 */
	private function withoutLiteralsAndComments(string $sql, string $platform): string {
		$hash = '';
		if (stripos($platform, 'mysql') !== false || stripos($platform, 'maria') !== false) {
			$hash = '|#[^\n]*';
		}

		$patterns = '~' . "'(?:[^']|'')*'" . '|"(?:[^"]|"")*"|`(?:[^`]|``)*`|/\*.*?\*/|--[^\n]*' . $hash . '~s';

		return (string) preg_replace_callback(
			$patterns,
			static fn (array $match): string => str_repeat(' ', strlen($match[0])),
			$sql
		);
	}//end withoutLiteralsAndComments()
}//end class
