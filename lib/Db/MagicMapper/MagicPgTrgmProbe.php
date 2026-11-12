<?php

/**
 * MagicPgTrgmProbe: whether the database offers pg_trgm.
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

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Exception;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Answer once per instance whether the pg_trgm extension is installed.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicPgTrgmProbe {
	/**
	 * The answer, null until asked.
	 *
	 * @var boolean|null
	 */
	private ?bool $available = null;

	/**
	 * Build the probe.
	 *
	 * @param IDBConnection   $db     The connection.
	 * @param LoggerInterface $logger Receives a failed check.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether pg_trgm is available; a failed check counts as no.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function available(): bool {
		$this->available ??= $this->isInstalled();

		return $this->available;
	}//end available()

	/**
	 * Ask the database.
	 *
	 * @return bool
	 */
	private function isInstalled(): bool {
		if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform === false) {
			return false;
		}

		try {
			$stmt = $this->db->prepare("SELECT COUNT(*) FROM pg_extension WHERE extname = 'pg_trgm'");

			return (int)$stmt->execute()->fetchOne() > 0;
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] Failed to check pg_trgm extension availability',
				context: ['error' => $e->getMessage()]
			);

			return false;
		}
	}//end isInstalled()
}//end class
