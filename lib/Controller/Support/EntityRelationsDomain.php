<?php

/**
 * OpenRegister entity relations domain
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

use OCA\OpenRegister\Service\Decision\DecisionVisibility;
use OCA\OpenRegister\Service\Decision\RelationDecisionService;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;

/**
 * The domain services of the entity relations API.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class EntityRelationsDomain {
	/**
	 * Bundle the services.
	 *
	 * @param PermissionHandlerInterface       $permissionHandler  RBAC verdict for object-bound relations.
	 * @param RelationDecisionService $relationDecisions  Takes the decision as its own object, under the ownership rule.
	 * @param DecisionVisibility      $decisionVisibility Masks a decision the caller may not see (REQ-ROD-002).
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly PermissionHandlerInterface $permissionHandler,
		public readonly RelationDecisionService $relationDecisions,
		public readonly DecisionVisibility $decisionVisibility,
	) {
	}//end __construct()
}//end class
