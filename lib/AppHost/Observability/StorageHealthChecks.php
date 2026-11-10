<?php

/**
 * OpenRegister AppHost — Storage Health Checks
 *
 * The database and filesystem health-check primitives.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability;

use OCP\IDBConnection;
use OCP\ITempManager;

/**
 * Runs the `database` and `filesystem` health checks.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-2.1
 */
class StorageHealthChecks {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db          Database connection (database check).
	 * @param ITempManager  $tempManager Temp-file manager (filesystem check).
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ITempManager $tempManager,
	) {
	}//end __construct()

	/**
	 * `SELECT 1` round-trip.
	 *
	 * @return array{0: bool, 1: string}
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-2.1
	 */
	public function database(): array {
		$result = $this->db->executeQuery('SELECT 1');
		$result->fetch();
		$result->closeCursor();
		return [true, ''];
	}//end database()

	/**
	 * Write + unlink a temp file.
	 *
	 * @return array{0: bool, 1: string}
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-2.1
	 */
	public function filesystem(): array {
		$path = $this->tempManager->getTemporaryFile('-apphost-health');
		if ($path === false || $path === '') {
			return [false, 'temp file unavailable'];
		}

		$bytes = file_put_contents($path, 'ok');
		$ok = ($bytes !== false);
		// The ITempManager cleans its files on shutdown; unlink eagerly anyway.
		@unlink($path);
		if ($ok === true) {
			return [true, ''];
		}

		return [false, 'temp write failed'];
	}//end filesystem()
}//end class
