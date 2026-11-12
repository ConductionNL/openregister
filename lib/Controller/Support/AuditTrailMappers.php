<?php

/**
 * OpenRegister audit trail mappers
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

use OCA\OpenRegister\Db\AuditTrailMapperInterface;

/**
 * The mappers and repositories of the audit trail API.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 */
final class AuditTrailMappers {
	/**
	 * Bundle the services.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper The audit trail mapper
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
	 */
	public function __construct(
		public readonly AuditTrailMapperInterface $auditTrailMapper,
	) {
	}//end __construct()
}//end class
