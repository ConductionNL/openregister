<?php

/**
 * CatalogueScope: limit a query on the database catalogue to the instance's own database.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-catalogue-query-reads-the-instances-own-database-only
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCP\IDBConnection;

/**
 * The condition that keeps a catalogue query inside the database the connection works in.
 *
 * `information_schema` describes every database on the server. A query on it
 * by table name alone lists the magic tables of a neighbouring Nextcloud as
 * this instance's, and answers a column lookup with the columns of every
 * same-named table. It also makes MariaDB scan every database where one would do.
 *
 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-catalogue-query-reads-the-instances-own-database-only
 */
final class CatalogueScope {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The connection.
	 */
	public function __construct(private readonly IDBConnection $db) {
	}//end __construct()

	/**
	 * The condition to add to a catalogue query's WHERE: `table_schema = <the connection's schema>`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-catalogue-query-reads-the-instances-own-database-only
	 */
	public function condition(): string {
		try {
			$postgres = ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform);
		} catch (\Throwable) {
			// A connection that cannot name its platform fails on the query itself; the callers handle that.
			$postgres = false;
		}

		if ($postgres === true) {
			return 'table_schema = current_schema()';
		}

		return 'table_schema = DATABASE()';
	}//end condition()
}//end class
