<?php

/**
 * OpenRegister Audit Recording
 *
 * The audit trail a service reads or writes, and the log it reports to when that fails.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Audit recording: the audit trail and the logger for an audit step that could not be completed.
 *
 * Used by the services that record on or read from the audit trail as part of
 * an archival step (the e-Depot transfer log, the MDTO event mapper and the
 * destruction scope).
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class AuditRecording {

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper The audit trail.
	 * @param LoggerInterface           $logger           PSR logger.
	 */
	public function __construct(
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
