<?php

/**
 * OpenRegister party domain
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

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCA\OpenRegister\Service\Party\PartyRoleServiceInterface;
use OCA\OpenRegister\Service\Party\PartyServiceInterface;

/**
 * The domain services of the party API.
 *
 * @spec openspec/specs/party-model/spec.md
 */
final class PartyDomain {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface $objects    Object service, to resolve the object a party sits on.
	 * @param PartyRoleServiceInterface $roles      The parties on an object.
	 * @param PartyServiceInterface     $parties    The party records.
	 * @param PartyIndicatorGuard       $indicators The declared effects.
	 *
	 * @spec openspec/specs/party-model/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objects,
		public readonly PartyRoleServiceInterface $roles,
		public readonly PartyServiceInterface $parties,
		public readonly PartyIndicatorGuard $indicators,
	) {
	}//end __construct()
}//end class
