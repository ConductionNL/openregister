<?php

/**
 * OpenRegister AggregationAccess
 *
 * The access decisions an aggregation runs under: schema RBAC, the organisation boundary, property RBAC and explicit reveal.
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

use OCA\OpenRegister\Db\MagicMapper\OrganisationScopeResolverInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;

/**
 * The access decisions an aggregation runs under: schema RBAC, the organisation boundary, property RBAC and explicit reveal.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final readonly class AggregationAccess {
	/**
	 * Bundle the collaborators.
	 *
	 * @param PermissionHandlerInterface         $permissionHandler   RBAC verdict on the schema's list action.
	 * @param OrganisationScopeResolverInterface $organizationHandler The organisation boundary the native SQL renders.
	 * @param PropertyRbacHandler|null           $propertyRbac        Withholds unreadable properties; absent, a governed property is withheld.
	 * @param ExplicitReveal|null                $explicitReveal      Trusted-code reveal state; absent, only a system operation may reveal.
	 */
	public function __construct(
		public PermissionHandlerInterface $permissionHandler,
		public OrganisationScopeResolverInterface $organizationHandler,
		public ?PropertyRbacHandler $propertyRbac = null,
		public ?ExplicitReveal $explicitReveal = null,
	) {
	}//end __construct()
}//end class
