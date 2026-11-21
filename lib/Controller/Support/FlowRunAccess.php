<?php

/**
 * OpenRegister flow run access
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

use OCA\OpenRegister\Service\Flow\FlowCaller;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuardInterface;
use OCA\OpenRegister\Service\Flow\FlowRunSignalInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;

/**
 * The domain services of the flow run API.
 *
 * @spec openspec/specs/flow-tooling/spec.md
 */
final class FlowRunAccess {
	/**
	 * Bundle the services.
	 *
	 * @param OrganisationServiceInterface   $organisationService Scopes the active-runs list to the caller's tenant.
	 * @param FlowRunnableGuardInterface     $guard               Whether the caller may run the flow a run belongs to.
	 * @param ?FlowCaller           $flowOwnership       Reads which flows the caller owns, from the
	 * @param ?FlowRunSignalInterface $signalService       The guarded signal seam the
	 *
	 * @spec openspec/specs/flow-tooling/spec.md
	 */
	public function __construct(
		public readonly OrganisationServiceInterface $organisationService,
		public readonly FlowRunnableGuardInterface $guard,
		public readonly ?FlowCaller $flowOwnership = null,
		public readonly ?FlowRunSignalInterface $signalService = null,
	) {
	}//end __construct()
}//end class
