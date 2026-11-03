<?php

/**
 * Turns a principal reference into the user ids it currently means.
 *
 * 🔴 "CURRENTLY" IS THE WHOLE POINT. A resolver is called on every answer, not
 * once at task creation. A municipal approval outlives the roster it was raised
 * against: a task assigned to the bezwaarcommissie in March must be answerable
 * by whoever sits on it in June, and a task assigned to the afdelingshoofd must
 * follow the post rather than the person who held it.
 *
 * Freezing the resolution would be simpler and faster and gets the domain
 * backwards in the direction that hurts: it keeps authorising somebody who has
 * left, and stops authorising the person now responsible. The cost of not
 * freezing is one membership lookup on a verb a human is performing by hand.
 *
 * WHY THIS LIVES HERE AND IS IMPLEMENTED ELSEWHERE
 * -----------------------------------------------
 * decidiq knows what a position on a body is; hermiq knows what a function is;
 * dossiq knows what a case role is. None of that can move into the engine
 * without the engine growing opinions about municipal organisation charts, and
 * OpenRegister must not name a consuming app (gate-27, ADR-022). So the
 * contract is here and the knowledge stays where it belongs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Principal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Principal;

/**
 * Contract for an app that owns a kind of principal.
 */
interface IPrincipalResolver {

	/**
	 * The type this resolver answers for, such as `position` or `function`.
	 *
	 * Namespace it if it could collide; the registry refuses a duplicate rather
	 * than resolving by load order, so two apps claiming one type is a startup
	 * failure and not a silent winner.
	 *
	 * @return string The principal type.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function type(): string;

	/**
	 * The user ids this reference means, right now.
	 *
	 * An empty array is a legitimate answer and means "nobody holds this at the
	 * moment" — a committee between appointments. It is NOT an error here; the
	 * caller decides what to do about it, and at task creation that is to fail
	 * the step loudly rather than raise a task for nobody.
	 *
	 * @param string $id The id within this type.
	 *
	 * @return array<int, string> The user ids, possibly empty.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function resolve(string $id): array;
}//end interface
