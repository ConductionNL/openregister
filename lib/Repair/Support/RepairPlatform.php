<?php

/**
 * OpenRegister repair platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair\Support
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

namespace OCA\OpenRegister\Repair\Support;

use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The database connection, app config and logger a repair step runs its migration with.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
 */
final readonly class RepairPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IDBConnection   $db     Database connection.
	 * @param IAppConfig      $config App config.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	public function __construct(
		public IDBConnection $db,
		public IAppConfig $config,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
