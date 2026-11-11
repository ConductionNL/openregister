<?php

/**
 * One check of a transition's authorization list against the caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-declarative-per-transition-authorization-gate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * The caller's groups, the schema the transition belongs to, and its role map once resolved.
 *
 * Built by PermissionHandler::isTransitionAuthorized() and never handed out of it (ADR-011 Rule 3).
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-declarative-per-transition-authorization-gate
 */
final class TransitionAuthorizationRun {

	/**
	 * The schema's role => assigned groups map, resolved on first use; null until then.
	 *
	 * @var array<string, array<int, string>>|null
	 */
	public ?array $roleGroupMap = null;

	/**
	 * Constructor for TransitionAuthorizationRun.
	 *
	 * @param array<string, int> $userGroupSet The caller's group ids as keys.
	 * @param Schema             $schema       Schema the transition belongs to (for named-role expansion).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $userGroupSet,
		public readonly Schema $schema,
	) {
	}//end __construct()
}//end class
