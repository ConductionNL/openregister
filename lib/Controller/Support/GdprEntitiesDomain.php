<?php

/**
 * OpenRegister gdpr entities domain
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

use OCA\OpenRegister\Service\Decision\DecisionVisibilityInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;

/**
 * The domain services of the gdpr entities API.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final class GdprEntitiesDomain {
	/**
	 * Bundle the services.
	 *
	 * @param OrganisationServiceInterface $organisationService Org-scoping helper
	 * @param DecisionVisibilityInterface  $decisionVisibility  Masks another reviewer's hidden decision
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function __construct(
		public readonly OrganisationServiceInterface $organisationService,
		public readonly DecisionVisibilityInterface $decisionVisibility,
	) {
	}//end __construct()
}//end class
