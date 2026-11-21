<?php

/**
 * OpenRegister permission handler rule services: match evaluation, events and scope resolution
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Permission
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

namespace OCA\OpenRegister\Service\Object\Permission;

use OCA\OpenRegister\Kernel\Contracts\Core\WriteElevationInterface;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolverInterface;
use OCA\OpenRegister\Service\Rbac\PartyRoleSlotResolver;
use OCA\OpenRegister\Service\Rbac\BreakGlassService;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Permission handler rule services: match evaluation, events and scope resolution.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class PermissionRuleServices {
	/**
	 * Bundle the services.
	 *
	 * @param ConditionMatcher     $conditionMatcher    Shared PHP-side match evaluator (ADR-011).
	 * @param ?IEventDispatcher    $eventDispatcher     Optional dispatcher for custom-scope events.
	 * @param ?ObjectScopeResolverInterface $objectScopeResolver Shared object-scope resolver; absent means a fresh one.
	 * @param ?ObjectGrantResolver $objectGrantResolver Shared per-object grant resolver; absent means a fresh one.
	 * @param ?WriteElevationInterface $writeElevation  The open elevation of the run in progress; absent, no refused write is elevated.
	 * @param ?PartyRoleSlotResolver $partyRoleSlots   Who occupies a party role on an object, for a grant to a slot;
	 *                                                 absent means it is resolved from the container, and a slot
	 *                                                 grants nothing when that fails.
	 * @param ?BreakGlassService     $breakGlass       Emergency takes on an object; absent means it is resolved
	 *                                                 from the container, and none admits when that fails.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly ConditionMatcher $conditionMatcher,
		public readonly ?IEventDispatcher $eventDispatcher = null,
		public readonly ?ObjectScopeResolverInterface $objectScopeResolver = null,
		public readonly ?ObjectGrantResolver $objectGrantResolver = null,
		public readonly ?WriteElevationInterface $writeElevation = null,
		public readonly ?PartyRoleSlotResolver $partyRoleSlots = null,
		public readonly ?BreakGlassService $breakGlass = null,
	) {
	}//end __construct()
}//end class
