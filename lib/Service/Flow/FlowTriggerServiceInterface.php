<?php

/**
 * FlowTriggerServiceInterface: the FlowTriggerService as the trigger listeners reach it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Fires a flow trigger event, typed for the listeners that translate app events into it.
 *
 * Declares only what callers outside the Service component use; the rest of the
 * class stays its own. Bound in ContractBindings to the same shared instance.
 */
interface FlowTriggerServiceInterface {

	/**
	 * Fire an event: queue a run for every flow wired to it.
	 *
	 * Never throws into the caller. A trigger runs inside the dispatch of a user
	 * action (a save, a share change); a failure to queue must not break that
	 * action, so it is logged and swallowed.
	 *
	 * @param string $event The event id (e.g. `object.created`).
	 * @param array $subject `{uuid, register, schema}` of the object, when there is one.
	 * @param string|null $user The user whose action fired the event.
	 * @param array $context Extra run-level metadata (the event payload, say).
	 *
	 * @return int The number of runs queued.
	 *
	 * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
	 */
	public function fire(string $event, array $subject = [], ?string $user = null, array $context = []): int;
}//end interface
