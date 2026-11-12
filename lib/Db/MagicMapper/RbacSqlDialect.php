<?php

/**
 * RbacSqlDialect
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The database dialect the raw-SQL access predicates are written in: which
 * platform is connected, how a value is quoted and how a column is quoted.
 *
 * The connection is resolved lazily through the container, once per platform
 * question: the RBAC handler is constructed on paths that do not need a
 * connection.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class RbacSqlDialect {

	/**
	 * Cached answer to whether the platform is PostgreSQL.
	 *
	 * @var boolean|null
	 */
	private ?bool $isPostgresCache = null;

	/**
	 * Cached answer to whether the provider is MySQL or MariaDB.
	 *
	 * @var boolean|null
	 */
	private ?bool $isMysqlCache = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The container the connection is read from.
	 * @param LoggerInterface    $logger    The log the access predicates report to.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the connected database is PostgreSQL.
	 *
	 * Mirrors ReferentialIntegrityService's null-safe `get_debug_type()`
	 * detection, which also tolerates a mocked connection returning null.
	 * Defaults to MariaDB syntax when the platform cannot be determined: an
	 * unparseable predicate errors the query rather than matching every row.
	 *
	 * @return bool True when the platform is PostgreSQL.
	 */
	public function isPostgres(): bool {
		if ($this->isPostgresCache === null) {
			$this->isPostgresCache = $this->probePostgres();
		}

		return $this->isPostgresCache;
	}//end isPostgres()

	/**
	 * Whether the connected database is MySQL or MariaDB.
	 *
	 * @return bool True for those two; a provider that cannot be read counts as true,
	 *              because doubling a backslash is safe everywhere.
	 */
	public function isMysql(): bool {
		if ($this->isMysqlCache === null) {
			$this->isMysqlCache = $this->probeMysql();
		}

		return $this->isMysqlCache;
	}//end isMysql()

	/**
	 * Quote a value for safe use in raw SQL.
	 *
	 * @param mixed $value Value to quote.
	 *
	 * @return string Quoted value safe for SQL.
	 */
	public function quote(mixed $value): string {
		$literal = $this->literal(value: $value);
		if ($literal !== null) {
			return $literal;
		}

		return $this->quotedString(value: (string)$value);
	}//end quote()

	/**
	 * A column name in the identifier quotes of the platform.
	 *
	 * @param string $columnName The bare column name.
	 *
	 * @return string The quoted column name.
	 */
	public function quotedColumn(string $columnName): string {
		if ($this->isPostgres() === true) {
			return '"'.$columnName.'"';
		}

		return '`'.$columnName.'`';
	}//end quotedColumn()

	/**
	 * Read the platform from the connection.
	 *
	 * @return bool True when the platform is PostgreSQL; false when it cannot be read.
	 */
	private function probePostgres(): bool {
		try {
			$platform = $this->container->get(IDBConnection::class)->getDatabasePlatform();
			return stripos(get_debug_type($platform), 'PostgreSQL') !== false;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[MagicRbacHandler] Could not determine the database platform for $contains — defaulting to MariaDB syntax',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end probePostgres()

	/**
	 * Read the provider from the connection.
	 *
	 * @return bool True for MySQL or MariaDB, and when the provider cannot be read.
	 */
	private function probeMysql(): bool {
		try {
			return $this->container->get(IDBConnection::class)->getDatabaseProvider() === IDBConnection::PLATFORM_MYSQL;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[MagicRbacHandler] Could not determine the database provider for quoting',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e->getMessage()]
			);
			return true;
		}
	}//end probeMysql()

	/**
	 * The SQL literal of a value that is not quoted as a string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null NULL, TRUE, FALSE or the number; null for anything quoted as a string.
	 */
	private function literal(mixed $value): ?string {
		return match (true) {
			$value === null => 'NULL',
			$value === true => 'TRUE',
			$value === false => 'FALSE',
			is_int($value) === true || is_float($value) === true => (string)$value,
			default => null,
		};
	}//end literal()

	/**
	 * A string in single quotes, escaped for the platform.
	 *
	 * MySQL and MariaDB read a backslash as an escape inside a string, so a
	 * value ending its quote through `\'` needs the backslash doubled as well.
	 *
	 * @param string $value The string.
	 *
	 * @return string The quoted string.
	 */
	private function quotedString(string $value): string {
		$escaped = $value;
		if ($this->isMysql() === true) {
			$escaped = str_replace('\\', '\\\\', $escaped);
		}

		$escaped = str_replace("'", "''", $escaped);
		return "'{$escaped}'";
	}//end quotedString()
}//end class
