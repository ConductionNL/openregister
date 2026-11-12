<?php

/**
 * OpenRegister correction auditing: the trail a correction is recorded on and the logger for when that fails
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
use Psr\Log\LoggerInterface;

/**
 * Correction auditing: the trail a correction is recorded on and the logger for when that fails.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class CorrectionAuditing {
	/**
	 * Bundle the services.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper The audit trail.
	 * @param LoggerInterface           $logger           PSR logger.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function __construct(
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
