<?php

/**
 * ObjectTransitionInterface: move an object through its lifecycle.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;


/**
 * Lists the lifecycle actions an object allows and performs one. Implemented by TransitionEngine.
 */
interface ObjectTransitionInterface {

	/**
	 * List actions whose `from` includes the object's current lifecycle value.
	 *
	 * @param string $objectId Object id/uuid/slug.
	 *
	 * Every entry carries the transition's declared `inputs` in the contract's
	 * own shape (`[{field, required}]`), EMPTY rather than absent when the
	 * transition declares none: empty is the positive statement "this
	 * transition accepts no payload", which is exactly what the allowlist in
	 * {@see resolveTransitionInputs()} enforces.
	 *
	 * Three modes answer this question. A static `transitions` map is read
	 * here; a `provider` tag delegates to the app that owns the state
	 * machine; a `graph` block derives moves from FK-scoped siblings. Static
	 * wins over both delegating modes wherever more than one is declared.
	 *
	 * @return list<array{action:string,to:string,requires:?string,description:?string,inputs:list<array{field:string,required:bool,label?:string,options?:list<array{value:string,label:string}>}>,label?:string,blocked?:bool}>
	 *
	 * guard + per-transition from/requires/description checks each add one branch; none can be removed
	 * without losing safety or fidelity.
	 * guard + per-transition from/requires/description checks each add one branch; none can be removed
	 * without losing safety or fidelity.
	 */
	public function availableActions(string $objectId): array;

	/**
	 * Apply a named transition to an object.
	 *
	 * When the transition declares `inputs`, the (optional) `$data` payload is
	 * validated against that allowlist and the accepted values are merged into
	 * the SAME write that flips the lifecycle field — so pre-save listeners
	 * (ObjectUpdatingEvent) observe the status change and the inputs together,
	 * and the normal schema validation / readOnly enforcement applies to them.
	 *
	 * @param string $objectId Object id/uuid/slug.
	 * @param string $action Transition action name.
	 * @param array<string, mixed> $data Optional input values for the transition's declared `inputs`.
	 *
	 * @return ObjectRowInterface The saved object after the transition.
	 *
	 * @throws \RuntimeException When the object/schema/transition is missing,
	 *                          the action is not allowed from the current
	 *                          state, or the underlying save is rejected.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update` permission
	 *                          on the object.
	 * @throws \OCA\OpenRegister\Exception\InvalidTransitionInputException When `$data` contains a key the
	 *                          transition does not declare, or a `required`
	 *                          input is absent or empty-string.
	 * @throws \Exception When the save path refuses the merged write — schema
	 *                          validation, readOnly enforcement or a vetoing
	 *                          hook ({@see HookStoppedException}) — exactly as
	 *                          it would refuse any other object write.
	 */
	public function transition(string $objectId, string $action, array $data = []): ObjectRowInterface;
}//end interface
