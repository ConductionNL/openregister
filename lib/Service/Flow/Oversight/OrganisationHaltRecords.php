<?php

/**
 * Where organisation halts are kept: the active list in app config, their history on the audit trail.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Oversight
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Oversight;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCP\IAppConfig;

/**
 * The two stores of OrganisationHaltService, wired by DI autowiring.
 *
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */
final class OrganisationHaltRecords {
	/**
	 * Bundle the stores.
	 *
	 * @param IAppConfig                $appConfig Where the active halts are kept.
	 * @param AuditTrailMapperInterface $audit     Where their history goes.
	 */
	public function __construct(
		public readonly IAppConfig $appConfig,
		public readonly AuditTrailMapperInterface $audit,
	) {
	}//end __construct()
}//end class
