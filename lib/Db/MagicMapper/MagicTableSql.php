<?php

/**
 * MagicTableSql: what raw SQL against a magic table needs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/architecture/adr-011-parameter-objects-for-unit-interfacing.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The capability to run hand-built SQL against a prefixed magic table.
 *
 * The magic-table handlers write their statements by hand (Nextcloud 32
 * compatibility), so each needs the connection, the system config that names
 * the table prefix (`dbtableprefix`) and the logger that records a failed
 * statement. ADR-011 Rule 4 named aggregate; autowired by the container.
 *
 * @spec openspec/architecture/adr-011-parameter-objects-for-unit-interfacing.md
 */
final class MagicTableSql {
	/**
	 * Hold the SQL collaborators.
	 *
	 * @param IDBConnection   $db     The connection the statements run on.
	 * @param IConfig         $config The system config holding the table prefix.
	 * @param LoggerInterface $logger Records failed and notable statements.
	 */
	public function __construct(
		public readonly IDBConnection $db,
		public readonly IConfig $config,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
