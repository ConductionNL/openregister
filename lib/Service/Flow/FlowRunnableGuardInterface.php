<?php

/**
 * FlowRunnableGuardInterface: what callers outside the component use of FlowRunnableGuard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\AppFramework\Http\JSONResponse;

/**
 * The run and edit refusals the flow run endpoints check first.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface FlowRunnableGuardInterface {

	/**
	 * Refuse the test run unless the caller may EDIT the flow being tested.
	 *
	 * `test()` is not a trigger a caller reaches because a flow happens to be
	 * running — it is the authoring loop. `startAt` restarts execution from any
	 * chosen node, skipping whatever an earlier node would otherwise have
	 * enforced, and `pins` substitutes stored output for a real step's result.
	 * Both are debug affordances for whoever is building the flow, and prior to
	 * this check the ONLY gate on reaching them was
	 * {@see self::refusalUnlessRunnable()} — organisation membership, which answers
	 * "is this flow yours to see at all", not "may you run it". On a
	 * single-organisation instance (the common case; see
	 * {@see \OCA\OpenRegister\Service\OrganisationService}) that check passes
	 * for every signed-in account, so any authenticated user could execute any
	 * flow, including ones they neither own nor may edit (or#3643).
	 *
	 * `flow.update` — not `flow.run` — is the right bar. `flow.run` (used by
	 * `FlowController::run()`, the editor's plain "Run Now") is seeded
	 * `@authenticated` by design, for the same reason RN-1 kept it out of the
	 * run-node endpoint: it says nothing about a caller's relationship to a
	 * SPECIFIC flow's authoring surface, only that they may trigger flows at
	 * all. `flow.update` is the right already required for every other editing
	 * verb on this flow (publish/draft/deprecate/adopt) — testing a flow's tail
	 * with pinned output is exactly as much "editing" as changing its JSON, and
	 * an admin who has restricted `flow.update` to an authors group is
	 * restricting exactly this.
	 *
	 * Fails CLOSED without the collaborator or the session, same posture as
	 * {@see self::refusalUnlessRunnable()}: no way to decide is a refusal, not an
	 * allow.
	 *
	 * @return JSONResponse|null A 401/403 refusal, or null when the caller may proceed.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
	 */
	public function refusalUnlessMayEditFlow(): ?JSONResponse;

	/**
	 * Refuse unless the caller may RUN this flow.
	 *
	 * WHY AT THE ENDPOINT AND NOT IN THE RESOLVER. `FlowLocator::resolveSubject()`
	 * loads with `_rbac: false`, and correctly so — the engine runs a flow as its
	 * owner, and background jobs and retries have no session to evaluate. But
	 * these endpoints inherited that bypass, and `retry()` in particular took a
	 * run UUID and retried it with no ownership check at all: any authenticated
	 * user could re-run anybody's flow. That is an IDOR (OWASP A01), and the fix
	 * belongs where the request enters, not in the engine.
	 *
	 * WHAT IT CHECKS. The flow is resolved through `FlowService`, which applies
	 * the organisation scoping and the per-flow guard. A caller who may not see
	 * the flow gets the SAME 404 as one asking for a flow that does not exist,
	 * so the endpoint cannot be used to discover which flow ids exist.
	 *
	 * Running is an EXTENSION verb — core's bitmask has no `run` — so per ADR-010
	 * Rule 4 it is enforced here, at the endpoint that performs the action,
	 * rather than by widening the RBAC vocabulary.
	 *
	 * @param string $flowId The flow being run.
	 *
	 * @return JSONResponse|null A refusal, or null when the caller may proceed.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
	 */
	public function refusalUnlessRunnable(string $flowId): ?JSONResponse;
}//end interface
