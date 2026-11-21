<?php

/**
 * OpenRegister processing log retention stores
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ProcessingLogMapperInterface;

/**
 * The processing log the retention job prunes and the audit trail that records each purge.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final readonly class ProcessingLogRetentionStores {
	/**
	 * Bundle the services.
	 *
	 * @param ProcessingLogMapperInterface $entries    Deletes expired entries.
	 * @param AuditTrailMapperInterface    $auditTrail Holds the record of each purge.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function __construct(
		public ProcessingLogMapperInterface $entries,
		public AuditTrailMapperInterface $auditTrail,
	) {
	}//end __construct()
}//end class
