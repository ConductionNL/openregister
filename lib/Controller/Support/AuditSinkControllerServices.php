<?php

/**
 * OpenRegister AuditSinkController services
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

use OCA\OpenRegister\Service\Audit\AuditSinkInterface;
use OCA\OpenRegister\Service\Audit\AuditSinkStatusInterface;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The collaborators of AuditSinkController, wired by DI autowiring.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class AuditSinkControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param AuditSinkInterface $sink The configured sink.
	 * @param AuditSinkStatusInterface $status The sink's health.
	 * @param IUserSession $userSession Current user session.
	 * @param IGroupManager $groupManager Group manager, for the admin gate.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function __construct(
		public readonly AuditSinkInterface $sink,
		public readonly AuditSinkStatusInterface $status,
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
