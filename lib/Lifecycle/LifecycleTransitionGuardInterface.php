<?php
/**
 * OpenRegister LifecycleTransitionGuardInterface
 *
 * A transition guard that judges the stored object as well as the incoming data.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Lifecycle
 * @package  OCA\OpenRegister\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Implement this instead of only `check()` when the guard decides on facts the
 * writer must not set, such as the applicant or the run an object belongs to.
 * OpenRegister then calls checkTransition() and never check(); check() stays
 * because the registry and older callers type against LifecycleGuardInterface.
 *
 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-a-transition-guard-can-judge-the-stored-object
 */
interface LifecycleTransitionGuardInterface extends LifecycleGuardInterface {
	/**
	 * Authorise (or deny) a transition, knowing the stored object.
	 *
	 * @param LifecycleGuardContext $context The incoming data, the stored object, the action and the caller.
	 *
	 * @return GuardResult Allow or deny + optional message.
	 *
	 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-a-transition-guard-can-judge-the-stored-object
	 */
	public function checkTransition(LifecycleGuardContext $context): GuardResult;
}//end interface
