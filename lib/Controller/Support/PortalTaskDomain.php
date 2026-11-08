<?php

/**
 * OpenRegister portal task domain
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

use OCA\OpenRegister\Service\Portal\PortalSubjectAssertionInterface;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryServiceInterface;
use OCA\OpenRegister\Service\Portal\PortalTaskService;
use OCP\IUserSession;

/**
 * Services of the portal task API.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
 */
final class PortalTaskDomain {
	/**
	 * Bundle the services.
	 *
	 * @param PortalSubjectAssertionInterface    $assertion   Verifies the acting subject.
	 * @param PortalTaskService         $portal      The subject-scoped read and completion.
	 * @param PortalTaskDeliveryServiceInterface $delivery    The delivery ledger.
	 * @param IUserSession              $userSession Names the operator on the delivery routes.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
	 */
	public function __construct(
		public readonly PortalSubjectAssertionInterface $assertion,
		public readonly PortalTaskService $portal,
		public readonly PortalTaskDeliveryServiceInterface $delivery,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
