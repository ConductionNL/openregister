<?php

/**
 * OpenRegister AggregationCaller
 *
 * Who runs an aggregation: the session, the active organisation and the placeholders resolved for them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCA\OpenRegister\Search\Service\Search\PlaceholderResolverInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IUserSession;

/**
 * Who runs an aggregation: the session, the active organisation and the placeholders resolved for them.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final readonly class AggregationCaller {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserSession                 $userSession         Active session, for the RBAC and cache-key user scope.
	 * @param OrganisationServiceInterface $organisationService Active-organisation lookup for the cache key.
	 * @param PlaceholderResolverInterface $placeholders        Resolves dynamic placeholders inside filters.
	 */
	public function __construct(
		public IUserSession $userSession,
		public OrganisationServiceInterface $organisationService,
		public PlaceholderResolverInterface $placeholders,
	) {
	}//end __construct()
}//end class
