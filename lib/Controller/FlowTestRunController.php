<?php

/**
 * The interactive test run: execute a flow now and hand back the trace.
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
 * @spec openspec/changes/or-flow-partial-run/specs/flow-partial-run/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Flow\FlowDeadEnd;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use stdClass;

/**
 * REST surface for the flow editor's "Test" button.
 *
 * Kept apart from the run history surface because it is the AUTHORING loop,
 * not a read of what already ran: it executes synchronously, it accepts
 * `startAt` and `pins`, and it is the one flow endpoint gated on
 * `flow.update` rather than on being allowed to see the flow.
 *
 * @spec openspec/changes/or-flow-partial-run/specs/flow-partial-run/spec.md
 */
class FlowTestRunController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string            $appName     The app id.
	 * @param IRequest          $request     The request.
	 * @param FlowRunService    $runner      Queues and executes a run.
	 * @param FlowLocator       $resolvers   Resolves the flow being tested.
	 * @param IUserSession      $userSession Who is asking, for attribution.
	 * @param FlowRunnableGuard $guard       Whether the caller may run and edit this flow.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FlowRunService $runner,
		private readonly FlowLocator $resolvers,
		private readonly IUserSession $userSession,
		private readonly FlowRunnableGuard $guard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Run a flow now and return its result — the interactive test run.
	 *
	 * Unlike a trigger, which queues a run for the worker, this runs the flow
	 * synchronously and hands back the whole trace, so an author gets the log and
	 * the items straight away. It carries the two authoring aids: `startAt` runs
	 * from a chosen node (run-from-here), and `pins` supplies stored output for
	 * named steps so the expensive ones are skipped. Together they are the
	 * "iterate on the tail of a flow" loop.
	 *
	 * The run is persisted like any other (trigger `test`), so it also shows up
	 * in the history — a test run is not a throwaway.
	 *
	 * CSRF IS enforced here (no `#[NoCSRFRequired]`), deliberately unlike its
	 * siblings on this controller. `resume()` and `signalByKey()` drop it because
	 * they are addressed by leaf apps and agents over Basic auth or app
	 * passwords, which carry no CSRF token — `TaskController`'s docblock states
	 * that reasoning. Nothing calls `test()` that way: it is a person's browser
	 * pressing "Test" in the flow editor, which has a token to send. There is no
	 * stated reason to accept a cross-site POST here, so this endpoint keeps the
	 * ordinary protection (or#3643).
	 *
	 * VERIFIED, not assumed, before removing the attribute (hydra gate-48's own
	 * question — "is any mutating caller unprotected right now"): neither this
	 * repo's `src/` nor `nextcloud-vue`'s `useFlowStore.js` (every OpenRegister
	 * flow API call this fleet's shared editor makes — `run()`, `create()`,
	 * `update()`, all of it — goes through `@nextcloud/axios`, which attaches
	 * the token itself) calls `/api/flow-runs/test` at all. The only OTHER
	 * caller found anywhere in the org is this app's own e2e suite
	 * (`tests/e2e/api-direct/flow-engine.spec.ts`), which authenticates over
	 * Basic auth ("no browser session is needed", its own docblock says) — the
	 * exact case NC's CSRF check does not apply to, for the same reason
	 * `resume()`/`signalByKey()` never needed the attribute either. Removing it
	 * here breaks nothing that calls this endpoint today; a future browser
	 * caller inherits protection automatically the moment it exists, the same
	 * way every other flow call already does.
	 *
	 * @return JSONResponse The finished run, or a 4xx when the flow is unknown
	 *                       or the caller may not edit it.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/or-flow-partial-run/specs/flow-partial-run/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowItems::normalise is a pure
	 * value-normaliser with no state to inject; wrapping it in a collaborator
	 * would add a constructor dependency to say the same thing.
	 */
	#[NoAdminRequired]
	public function test(): JSONResponse {
		$editRefusal = $this->guard->refusalUnlessMayEditFlow();
		if ($editRefusal !== null) {
			return $editRefusal;
		}

		$flowId = trim((string)$this->request->getParam('flowId', ''));
		if ($flowId === '') {
			return new JSONResponse(['error' => 'A test run needs a flowId.'], Http::STATUS_BAD_REQUEST);
		}

		$refusal = $this->guard->refusalUnlessRunnable(flowId: $flowId);
		if ($refusal !== null) {
			return $refusal;
		}

		$flow = $this->resolvers->resolveFlow(flowId: $flowId);
		if ($flow === null) {
			return new JSONResponse(['error' => 'No such flow: ' . $flowId], Http::STATUS_NOT_FOUND);
		}

		$startAt = trim((string)$this->request->getParam('startAt', ''));
		if ($startAt === '') {
			$startAt = null;
		}

		$pins = (array)$this->request->getParam('pins', []);

		$seed = null;
		$seedParam = $this->request->getParam('seedItems');
		if ($seedParam !== null) {
			$seed = FlowItems::normalise(value: $seedParam);
		}

		// Attribute the test run to the caller. Without this the run is
		// ownerless, so `context['triggeredBy']` is null and every
		// attribution-requiring node refuses — ObjectWriteNode returns "this
		// flow run has no owner". An interactive test run has a session by
		// definition, so there is no reason for it to be the one dispatch path
		// that discards its actor. Same defect class as or#2158 in
		// FlowMcpToolProvider::runFlow().
		// 🔴 A REFUSAL MUST NOT LEAVE HERE AS A 500. A dead end, or a flow with
		// no published version, is the engine DECLINING to run something — an
		// answer the author can act on. Unwrapped, both reached the editor as
		// an HTML error page, which reads as "the server is broken" and sends
		// the author to the wrong place entirely.
		try {
			$run = $this->runner->queue(
				flowId: $flowId,
				subject: [],
				trigger: 'test',
				context: ['pins' => $pins],
				user: $this->userSession->getUser()?->getUID()
			);

			$run = $this->runner->execute(
				run: $run,
				flow: $flow,
				subject: new stdClass(),
				seedItems: $seed,
				startAt: $startAt
			);
		} catch (FlowLifecycleRefused $e) {
			return new JSONResponse(
				[
					'error' => $e->getMessage(),
					'reason' => $e->getReason(),
					'lifecycleStatus' => $e->getState(),
					'flowId' => $e->getFlowId(),
				],
				Http::STATUS_CONFLICT
			);
		} catch (FlowDeadEnd $e) {
			return new JSONResponse(
				['error' => $e->getMessage(), 'reason' => 'dead-end', 'flowId' => $flowId],
				Http::STATUS_CONFLICT
			);
		}//end try

		return new JSONResponse($run->jsonSerialize());
	}//end test()
}//end class
