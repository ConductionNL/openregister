<?php

/**
 * MagicMapper RBAC Resolver Bundle
 *
 * Small immutable value object grouping the three shared RBAC resolver
 * collaborators — the object-scope resolver, the per-object grant resolver and
 * the deny-grammar reader — so they travel as one constructor dependency
 * instead of three loose parameters.
 *
 * Each field is nullable so that adding the bundle is not a fatal at existing
 * construction sites; consumers fall back to a freshly built resolver when a
 * field is null, exactly as they did when these were separate parameters.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper RBAC capabilities
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;

/**
 * Immutable bundle of the shared RBAC resolvers used by MagicRbacHandler.
 */
final class RbacResolvers {

	/**
	 * Constructor for RbacResolvers
	 *
	 * @param ObjectScopeResolver|null $objectScopeResolver Shared object-scope resolver; nullable so adding it is not
	 *                                                      a fatal at existing construction sites.
	 * @param ObjectGrantResolver|null $objectGrantResolver Shared per-object grant resolver; nullable for the same reason.
	 * @param DenyResolver|null $denyResolver Shared deny-grammar reader; nullable for the same reason.
	 */
	public function __construct(
		public readonly ?ObjectScopeResolver $objectScopeResolver = null,
		public readonly ?ObjectGrantResolver $objectGrantResolver = null,
		public readonly ?DenyResolver $denyResolver = null,
	) {
	}//end __construct()
}//end class
