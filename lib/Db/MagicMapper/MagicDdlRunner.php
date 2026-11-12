<?php

/**
 * MagicDdlRunner: run a DDL statement whose failure must not stop a table sync.
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
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Run DDL statements that may fail without failing the table sync.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicDdlRunner {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     The connection.
	 * @param LoggerInterface $logger Receives a failure.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run a statement; a failure is logged.
	 *
	 * @param string          $sql     The statement.
	 * @param MagicDdlFailure $failure What to log on failure.
	 *
	 * @return bool Whether the statement succeeded.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function attempt(string $sql, MagicDdlFailure $failure): bool {
		try {
			$this->db->executeStatement($sql);
			return true;
		} catch (\Exception $e) {
			$this->logger->warning(
				message: $failure->message,
				context: $failure->context + ['error' => $e->getMessage()]
			);
			return false;
		}
	}//end attempt()
}//end class
