<?php

/**
 * QueryPreviewService — the first rows of a query-backed schema's SQL, before it is saved.
 *
 * An administrator who writes a query for a query-backed schema sees what it
 * returns before saving it: the statement guard runs first, then the query is
 * read as a derived table, at most 50 rows, inside the read-only scope
 * (design D-3). A refusal or a database error is answered with a fixed
 * sentence and the database's error class, never its message, which can name
 * other tables.
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
 *
 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dbal;

use Doctrine\DBAL\Exception as DbalException;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Source;

/**
 * Previews a query against a database source (design D-3).
 *
 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
 */
class QueryPreviewService {

	/**
	 * The most rows a preview answers.
	 *
	 * @var integer
	 */
	public const MAX_ROWS = 50;

	/**
	 * The alias of the derived table the query is read as.
	 *
	 * @var string
	 */
	private const DERIVED_ALIAS = 'or_src';

	/**
	 * The statement timeout of a preview, in milliseconds.
	 *
	 * @var integer
	 */
	private const TIMEOUT_MS = 10000;

	/**
	 * Constructor.
	 *
	 * @param DbalConnectionFactoryInterface $connectionFactory Opens the source's connection.
	 * @param ReadOnlyQueryGuard|null        $guard             Refuses SQL that is not a single read.
	 * @param ReadOnlyReadScope|null         $scope             Runs the read read-only with a timeout.
	 */
	public function __construct(
		private readonly DbalConnectionFactoryInterface $connectionFactory,
		private readonly ?ReadOnlyQueryGuard $guard = null,
		private readonly ?ReadOnlyReadScope $scope = null,
	) {
	}//end __construct()

	/**
	 * The columns and first rows a query returns.
	 *
	 * @param Source $source The database source.
	 * @param string $query  The query an administrator is writing.
	 *
	 * @return array{columns: array<int, array{name: string, type: string}>, rows: array<int, array<string, mixed>>}
	 *
	 * @throws InvalidArgumentException When the guard refuses the query; the message names the rule.
	 * @throws DbalConnectionException  When the source cannot be connected to.
	 * @throws QueryPreviewException    When the database refuses the query.
	 *
	 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
	 */
	public function preview(Source $source, string $query): array {
		$sql = trim($query);
		if ($sql === '') {
			throw new InvalidArgumentException('A query is required.');
		}

		$connection = $this->connectionFactory->getConnection(source: $source);
		($this->guard ?? new ReadOnlyQueryGuard())->assert(sql: $sql, platform: ReadOnlyReadScope::platformName(connection: $connection));

		$qb = $connection->createQueryBuilder();
		$qb->select('*')->from('(' . rtrim($sql, "; \t\n\r") . ') ' . self::DERIVED_ALIAS)->setMaxResults(self::MAX_ROWS);

		try {
			$rows = ($this->scope ?? new ReadOnlyReadScope())->run(
				connection: $connection,
				read: static fn (): array => $qb->executeQuery()->fetchAllAssociative(),
				timeoutMs: self::TIMEOUT_MS
			);
		} catch (DbalException $exception) {
			throw new QueryPreviewException(errorClass: (new \ReflectionClass($exception))->getShortName(), previous: $exception);
		}

		return ['columns' => self::columnsOf(rows: $rows), 'rows' => $rows];
	}//end preview()

	/**
	 * The columns of the rows, with the type their values have.
	 *
	 * A column's type is `integer`, `number`, `boolean` or `string` as the
	 * driver returned the values, and `null` when every value is null.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<int, array{name: string, type: string}> The columns, in order.
	 *
	 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
	 */
	public static function columnsOf(array $rows): array {
		if ($rows === []) {
			return [];
		}

		$columns = [];
		foreach (array_keys($rows[0]) as $name) {
			$types = [];
			foreach ($rows as $row) {
				$type = self::typeOf(value: ($row[$name] ?? null));
				if ($type !== null) {
					$types[$type] = true;
				}
			}

			$columns[] = ['name' => (string)$name, 'type' => self::merged(types: array_keys($types))];
		}

		return $columns;
	}//end columnsOf()

	/**
	 * The type of one value, or null for null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The type.
	 */
	private static function typeOf(mixed $value): ?string {
		return match (true) {
			$value === null => null,
			is_bool($value) => 'boolean',
			is_int($value) => 'integer',
			is_float($value) => 'number',
			default => 'string',
		};
	}//end typeOf()

	/**
	 * One type for a column whose values have these types.
	 *
	 * @param array<int, string> $types The types seen.
	 *
	 * @return string The type: `null` for none, `number` for integers mixed with numbers, else `string` when mixed.
	 */
	private static function merged(array $types): string {
		sort($types);

		if ($types === []) {
			return 'null';
		}

		if ($types === ['integer', 'number']) {
			return 'number';
		}

		if (count($types) === 1) {
			return $types[0];
		}

		return 'string';
	}//end merged()
}//end class
