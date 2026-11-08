<?php

/**
 * OpenRegister object relations domain
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

use OCA\OpenRegister\Service\Audit\PurposeGuardInterface;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCA\OpenRegister\Service\Relation\ObjectTreeService;

/**
 * The domain services of the object relations API.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class ObjectRelationsGuards {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectReadAccess  $access       Resolves an object under the caller's own RBAC.
	 * @param ObjectTreeService $trees        The bounded walk along a schema's parent property.
	 * @param PurposeGuardInterface      $purposeGuard The doelbinding guard the object read runs, asked before a tree or a graph is answered.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		public readonly ObjectReadAccess $access,
		public readonly ObjectTreeService $trees,
		public readonly PurposeGuardInterface $purposeGuard,
	) {
	}//end __construct()
}//end class
