<?php

/**
 * ConfigurationSyncResult
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;

/**
 * How one synchronisation of a configuration ended (ADR-011 Rule 2): its
 * status, when it ran, and a message about the result.
 *
 * @spec exclude ADR-011 parameter object of ConfigurationMapper::updateSyncStatus(), not a capability of its own
 */
final class ConfigurationSyncResult {

	/**
	 * Constructor
	 *
	 * @param string   $status   Sync status: 'success', 'failed', 'pending'.
	 * @param DateTime $syncDate Synchronization timestamp.
	 * @param string   $message  Optional message about the sync result; not stored.
	 */
	public function __construct(
		public readonly string $status,
		public readonly DateTime $syncDate,
		public readonly string $message = '',
	) {
	}//end __construct()
}//end class
