<?php

/**
 * OpenRegister audit recording
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
 * Writing an audit trail entry: the trail it is written to, and the log a
 * failed write is reported to instead of failing the change it describes.
 *
 * Consumed by ObjectSlugBackfillLedger, GrantChangeAuditor and DeleteObject (ADR-011 Rule 4).
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
final class AuditRecording {
	/**
	 * Bundle the audit trail and its failure log.
	 *
	 * @param AuditTrailMapperInterface $trail  The audit trail entries are written to.
	 * @param LoggerInterface           $logger The log a failed audit write is reported to.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md
	 */
	public function __construct(
		public readonly AuditTrailMapperInterface $trail,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
