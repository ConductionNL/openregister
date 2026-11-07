<?php

/**
 * OpenRegister case controller services
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

use OCA\OpenRegister\Service\Case\CasePlanAuthorizationService;
use OCA\OpenRegister\Service\Case\CasePlanServiceInterface;
use OCA\OpenRegister\Service\Case\ZaaktypeCaseSkeletonMapperInterface;
use OCP\IUserSession;

/**
 * The services of the case API.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
final class CaseControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param CasePlanServiceInterface              $plans         The authorized case layer.
	 * @param ZaaktypeCaseSkeletonMapperInterface   $zaaktypes     The pure zaaktype mapping.
	 * @param CasePlanAuthorizationService $authorization Identity checks for the anchorless route.
	 * @param IUserSession                 $userSession   Names the acting identity.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	public function __construct(
		public readonly CasePlanServiceInterface $plans,
		public readonly ZaaktypeCaseSkeletonMapperInterface $zaaktypes,
		public readonly CasePlanAuthorizationService $authorization,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
