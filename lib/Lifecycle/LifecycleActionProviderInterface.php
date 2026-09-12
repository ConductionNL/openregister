<?php

/**
 * OpenRegister LifecycleActionProviderInterface
 *
 * Public contract apps implement to answer "which lifecycle moves does this
 * object offer right now" when the answer cannot be written in the schema.
 * Providers are registered via DI tag; the schema's
 * `x-openregister-lifecycle.provider` field names the tag.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Apps implement this interface to supply an object's available actions.
 *
 * This is the third lifecycle mode, beside the schema's static `transitions`
 * map and its `graph` block. It exists for state machines whose shape is data
 * rather than schema: a per-object workflow template, a per-case-type status
 * list, a policy table an administrator edits. OpenRegister should not learn
 * an app's model to answer such a question, so it delegates exactly as it
 * already delegates guards (`LifecycleGuardInterface`) and actions
 * (`LifecycleActionInterface`).
 *
 * Providers are read-only — they MUST NOT mutate the object, and MUST NOT
 * write anything derived from it. They are called on a GET request that the
 * caller may repeat at will. Side effects (notifications, cascades,
 * derived-field maintenance) belong on `ObjectTransitionedEvent` listeners,
 * not in the provider.
 *
 * A provider that cannot answer MUST throw. Returning an empty list says
 * "this object genuinely offers no moves", which a client renders as a dead
 * but legitimate timeline; a throw is reported to the caller as an upstream
 * failure (HTTP 502) instead, so the two are never confused.
 */
interface LifecycleActionProviderInterface {
	/**
	 * List the actions the object offers from its current state.
	 *
	 * Entries are published to the client in declaration order. `inputs` is
	 * normalised by OpenRegister through the same allowlist the write path
	 * uses, so a malformed entry is dropped rather than published. The
	 * optional `label` carries a human-readable name for the target state,
	 * and the optional `blocked` marks a move the object can see but cannot
	 * take yet, its reason belonging in `description`.
	 *
	 * @param array<string, mixed> $object The loaded object payload at its current state.
	 * @param string $userId The uid of the caller.
	 *
	 * @return list<array{action:string,to:string,requires:?string,description:?string,inputs:list<array{field:string,required:bool}>,label?:string,blocked?:bool}>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function availableActions(array $object, string $userId): array;
}//end interface
