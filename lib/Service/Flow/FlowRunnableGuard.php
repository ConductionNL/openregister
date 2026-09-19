<?php

/**
 * Whether a caller may run, or edit, one flow.
 *
 * 🔴 THE TWO REFUSALS LIVE TOGETHER BECAUSE THREE ENDPOINTS ASK THEM. They
 * used to be private methods on `FlowRunController`, which meant the run
 * endpoints, the migration endpoints and the interactive test run could only
 * share them by living in one class. Splitting that class without lifting the
 * guards out first would have produced a second copy of an authorization
 * check, and two copies of one check drift: the copy nobody edits is the one
 * still letting the caller through.
 *
 * Both fail CLOSED when their collaborator is absent. No way to decide is a
 * refusal, never an allow.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Exception\FlowRunRefused;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * Answers the run and edit refusals the flow endpoints share.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
 */
class FlowRunnableGuard {

	/**
	 * Constructor.
	 *
	 * @param FlowService|null $flows  Resolves a flow under the organisation scoping and the per-flow guard.
	 *                                 Nullable because absent must SCOPE, never widen: without it every
	 *                                 flow answers "no such flow".
	 * @param FlowAccess|null  $access The flow action-rights matrix. Nullable for the same reason.
	 */
	public function __construct(
		private readonly ?FlowService $flows = null,
		private readonly ?FlowAccess $access = null,
	) {
	}//end __construct()

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
	 */
	public function refusalUnlessRunnable(string $flowId): ?JSONResponse {
		if ($this->flows === null) {
			// Fail CLOSED. Without the collaborator there is no way to decide,
			// and an unguarded run is what this method exists to prevent.
			return new JSONResponse(['error' => 'No such flow: ' . $flowId], Http::STATUS_NOT_FOUND);
		}

		try {
			$flow = $this->flows->find(uuid: $flowId);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => 'No such flow: ' . $flowId], Http::STATUS_NOT_FOUND);
		}

		// 🔴 EXISTENCE AND ORGANISATION WERE THE WHOLE CHECK. On the
		// single-organisation instance that is the common case, that is any
		// signed-in user running any flow — the exposure this controller's own
		// docblock names (or#3643). The per-flow decision now lives in one
		// place and every run path asks it, so a flow's owner governs its runs
		// the way `flow_register.json` always implied.
		try {
			$this->flows->assertRunnable(flow: $flow);
		} catch (FlowRunRefused $refused) {
			$status = Http::STATUS_FORBIDDEN;
			if ($refused->getVerdict() === FlowRunAuthorization::NO_SESSION) {
				$status = Http::STATUS_UNAUTHORIZED;
			}

			return new JSONResponse(
				['error' => $refused->getMessage(), 'verdict' => $refused->getVerdict()],
				$status
			);
		}

		return null;
	}//end refusalUnlessRunnable()

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
	public function refusalUnlessMayEditFlow(): ?JSONResponse {
		if ($this->access === null) {
			return new JSONResponse(['error' => 'Flow authorization is unavailable.'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->access->currentUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not signed in.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->access->may(user: $user, action: 'flow.update') === true) {
			return null;
		}

		return new JSONResponse(
			['error' => 'You do not have the "flow.update" right.'],
			Http::STATUS_FORBIDDEN
		);
	}//end refusalUnlessMayEditFlow()
}//end class
