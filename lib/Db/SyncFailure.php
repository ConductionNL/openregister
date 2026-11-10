<?php

/**
 * SyncFailure: the error status and message of a failed sync step.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * An error status for a sync record, with the message that explains it.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final class SyncFailure {

	/**
	 * Constructor.
	 *
	 * @param string $status  The error status.
	 * @param string $message The error detail.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $message,
	) {
	}//end __construct()
}//end class
