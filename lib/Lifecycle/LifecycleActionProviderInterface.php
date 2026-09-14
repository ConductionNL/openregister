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
 * A provider answers both halves of the mode. `availableActions()` is the
 * read: it MUST NOT mutate the object, because it is called on a GET the
 * caller may repeat at will. `execute()` is the write: it performs the move
 * it offered, and it is the only place the move happens.
 *
 * BOTH ARE MANDATORY ON PURPOSE. An interface where the write half were
 * optional would let an app declare provider mode, publish a timeline of
 * moves, and have every click refused — which is the defect this pair exists
 * to close (openregister#3679). A provider with nothing to offer says so by
 * answering an empty list; a provider that refuses a particular move says so
 * by throwing from `execute()`. Neither needs a half-implemented contract.
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

	/**
	 * Perform one of the moves this provider offered.
	 *
	 * THE PROVIDER DOES THE WRITE, OpenRegister does not. That is the whole
	 * shape of this method and it is deliberate. An app that owns a state
	 * machine owns more than the field the status lands in: dossiq's
	 * `StatusTransitionService::execute()` re-evaluates the transition's
	 * guards, takes an optimistic version lock, refuses a closing move that
	 * carries no result, writes the status record, and dispatches the
	 * transition's side effects. A contract that answered "here is the new
	 * value, you save it" would strand every one of those, and OpenRegister
	 * would have to learn the app's model to run them — which is exactly what
	 * provider mode exists to avoid.
	 *
	 * It also means there is ONE authority. The provider re-validates the move
	 * with the same reader `availableActions()` used, so a move that became
	 * unreachable between the read and the click is refused by the same code
	 * that decided it was reachable, not by a second derivation that can
	 * disagree with the first.
	 *
	 * THE RETURN VALUE IS A REPORT, NOT THE OBJECT. OpenRegister re-reads the
	 * object through its own read path after this returns, so what it answers
	 * the client with is the stored truth rather than the provider's echo of
	 * it. Exactly one key is read off this array: `to`, the state the object
	 * moved into, used for the `ObjectTransitionedEvent`. When it is absent
	 * OpenRegister reads the annotation's lifecycle field off the re-read
	 * object instead, so a provider whose own result shape says nothing about
	 * the target state — dossiq's returns `status`/`statusRecord`/
	 * `dispatchedActions`/`version` — can return it verbatim. Every other key
	 * is ignored and is free for the app's own callers.
	 *
	 * REFUSING A MOVE AND BEING BROKEN ARE DIFFERENT THROWS, and an app MUST
	 * keep them apart because the caller's status code depends on it:
	 *
	 * - A REFUSAL — a guard said no, the object already moved, a required
	 *   input is missing, the object is not one this provider knows — throws
	 *   any ordinary `RuntimeException`. Its message reaches the user as HTTP
	 *   422, so write it as a sentence a handler can act on.
	 * - BEING BROKEN — the workflow template will not parse, storage is down,
	 *   a dependency is missing — throws
	 *   {@see \OCA\OpenRegister\Exception\LifecycleProviderException}, which
	 *   reaches the caller as HTTP 502. Anything else that escapes (a
	 *   `TypeError`, an `Error`) is wrapped into one, because an unplanned
	 *   failure is never a refusal.
	 *
	 * A refusal MUST leave the object untouched: it is reported to a user as
	 * "that move did not happen", and a half-applied refusal makes that a lie.
	 *
	 * A move MUST NOT delete the object. OpenRegister answers the write
	 * endpoint with the transitioned object, so an object that is gone
	 * afterwards leaves it nothing truthful to answer with, and it reports
	 * that as a provider failure.
	 *
	 * @param array<string, mixed> $object The loaded object payload as it stood before the move.
	 * @param string $userId The uid of the caller, empty when there is no session user.
	 * @param string $action The action name, one this provider published from `availableActions()`.
	 * @param array<string, mixed> $data The input values the caller supplied, keyed by field.
	 *
	 * @return array<string, mixed> The provider's report of the move. `to` is read when present;
	 *                              every other key is the app's own.
	 *
	 * @throws \RuntimeException When the move is refused.
	 * @throws \OCA\OpenRegister\Exception\LifecycleProviderException When the provider is broken.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function execute(array $object, string $userId, string $action, array $data): array;
}//end interface
