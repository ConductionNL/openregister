<?php

/**
 * OpenRegister audit trail platform
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

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The platform services of the audit trail API.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 */
final class AuditTrailPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession  $userSession  Active user session for caller identity.
	 * @param IGroupManager $groupManager Group manager for admin / role checks.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
