<?php

/**
 * OpenRegister integrity cascade controls
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;

/**
 * What a referential-integrity cascade is checked against and recorded in.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class IntegrityCascadeControls {
	/**
	 * Bundle the services.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit trail mapper for integrity action logging.
	 * @param ArchivalRetentionGuard    $archivalGuard    Decides whether a cascade target is legally retained.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly ArchivalRetentionGuard $archivalGuard,
	) {
	}//end __construct()
}//end class
