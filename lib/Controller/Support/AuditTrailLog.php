<?php

/**
 * OpenRegister audit trail domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\Audit\AuditTrailPageService;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister;
use OCA\OpenRegister\Service\LogService;

/**
 * The domain services of the audit trail API.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 */
final class AuditTrailLog {
	/**
	 * Bundle the services.
	 *
	 * @param LogService               $logService       The log service
	 * @param AuditHashService         $auditHashService The audit hash chain service
	 * @param ReadableAuditTrailLister $readableLister   Lists the trail within one caller's read scope.
	 * @param AuditTrailPageService    $auditPage        The audit page's keyset list and export.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
	 */
	public function __construct(
		public readonly LogService $logService,
		public readonly AuditHashService $auditHashService,
		public readonly ReadableAuditTrailLister $readableLister,
		public readonly AuditTrailPageService $auditPage,
	) {
	}//end __construct()
}//end class
