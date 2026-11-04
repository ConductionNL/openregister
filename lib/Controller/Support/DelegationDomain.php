<?php

/**
 * OpenRegister delegation domain
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

use OCA\OpenRegister\Service\Delegation\DelegationConsentService;
use OCA\OpenRegister\Service\Delegation\DelegationNotifier;
use OCA\OpenRegister\Service\OrganisationServiceInterface;

/**
 * The domain services of the delegation API.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
final class DelegationDomain {
	/**
	 * Bundle the services.
	 *
	 * @param DelegationConsentService $consent       Owns the lifecycle and its rules.
	 * @param DelegationNotifier       $notifier      Tells a person they were asked.
	 * @param OrganisationServiceInterface      $organisations Bounds who a caller may ask.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function __construct(
		public readonly DelegationConsentService $consent,
		public readonly DelegationNotifier $notifier,
		public readonly OrganisationServiceInterface $organisations,
	) {
	}//end __construct()
}//end class
