<?php

/**
 * Moving runs in flight onto another version of their flow.
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
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationService;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST surface for moving runs between versions of their flow.
 *
 * 🔴 NEVER AUTOMATIC, AND THAT IS THE POINT. Publishing a new version of a
 * flow moves nothing. A migration is a deliberate act by a named person with
 * a reason, validated first and refused when the run has nowhere to land, so
 * it is its own endpoint pair and its own controller rather than two more
 * verbs on the read surface for runs.
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md
 */
class FlowRunMigrationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                       $appName     The app id.
	 * @param IRequest                     $request     The request.
	 * @param FlowRunMapper                $mapper      The run store.
	 * @param IUserSession                 $userSession Who is asking.
	 * @param FlowRunnableGuard            $guard       Whether the caller may run this flow at all.
	 * @param FlowRunMigrationService|null $migrations  Moves runs onto another version. Nullable and
	 *                                                  appended last; absent, the endpoints report the
	 *                                                  surface unavailable rather than migrating
	 *                                                  unvalidated.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FlowRunMapper $mapper,
		private readonly IUserSession $userSession,
		private readonly FlowRunnableGuard $guard,
		private readonly ?FlowRunMigrationService $migrations = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Move one run onto another version of its flow, or say what that would do.
	 *
	 * 🔴 NEVER AUTOMATIC. Publishing a version moves nothing; this is the
	 * deliberate exception, and it needs a reason, a named actor and a marking
	 * that fits. `dryRun` answers the same verdict without writing, so a UI can
	 * show an administrator what would happen before they commit.
	 *
	 * The guard is the flow's `run` right, the same one `retry` and `resume`
	 * take, because moving a run in flight is at least as consequential as
	 * re-running it.
	 *
	 * @param string $uuid The run uuid.
	 *
	 * @return JSONResponse The outcome, or a 4xx naming what stood in the way.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function migrate(string $uuid): JSONResponse {
		if ($this->migrations === null) {
			// Fail CLOSED, like `refuseUnlessRunnable`: without the collaborator
			// there is no validator, and a migration that skipped validation is
			// the silent move this whole change exists to prevent.
			return new JSONResponse(
				['error' => 'Run migration is not available on this instance.'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		try {
			$run = $this->mapper->findByUuid($uuid);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'No such run'], Http::STATUS_NOT_FOUND);
		}

		$refusal = $this->guard->refusalUnlessRunnable(flowId: (string)$run->getFlowId());
		if ($refusal !== null) {
			return $refusal;
		}

		$actor = $this->userSession->getUser();
		if ($actor === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$mapping = $this->request->getParam('mapping', []);
		$nodeMapping = [];
		if (is_array($mapping) === true) {
			$nodeMapping = $mapping;
		}

		$outcome = $this->outcomeFor(uuid: $uuid, actorUid: $actor->getUID(), mapping: $nodeMapping);

		// A dry run is not a refusal even though it did not migrate, so the two
		// are told apart before the status is chosen: answering 422 for a
		// successful preview would make every UI treat it as a failure.
		if ($outcome['dryRun'] === true) {
			return new JSONResponse($outcome);
		}

		if ($outcome['migrated'] === false) {
			return new JSONResponse($outcome, Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse($outcome);
	}//end migrate()

	/**
	 * Move every run pinned to one version of a flow onto another.
	 *
	 * Reports PER RUN. A bulk migration that answered only a count would leave
	 * an administrator believing every run moved, and the ones that did not are
	 * exactly the ones somebody has to go and look at.
	 *
	 * @param string $flow The flow uuid.
	 *
	 * @return JSONResponse The report, or a 4xx naming what stood in the way.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-runs-can-be-migrated-in-bulk-per-version
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function migrateRuns(string $flow): JSONResponse {
		if ($this->migrations === null) {
			return new JSONResponse(
				['error' => 'Run migration is not available on this instance.'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$refusal = $this->guard->refusalUnlessRunnable(flowId: $flow);
		if ($refusal !== null) {
			return $refusal;
		}

		$actor = $this->userSession->getUser();
		if ($actor === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$reason = trim((string)$this->request->getParam('reason', ''));
		if ($reason === '') {
			return new JSONResponse(
				['error' => 'Say why these runs are being moved. The reason is kept on each of them.'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$mapping = $this->request->getParam('mapping', []);

		$nodeMapping = [];
		if (is_array($mapping) === true) {
			$nodeMapping = $mapping;
		}

		return new JSONResponse(
			$this->migrations->migrateRunsOfVersion(
				flowId: $flow,
				sourceVersion: (int)$this->request->getParam('sourceVersion', 0),
				targetVersion: (int)$this->request->getParam('targetVersion', 0),
				reason: $reason,
				actor: $actor->getUID(),
				mapping: $nodeMapping,
			)
		);
	}//end migrateRuns()
	/**
	 * Ask the service for a preview or for the write, as the request says.
	 *
	 * 🔴 THE PREVIEW AND THE WRITE ARE SEPARATE CALLS, and the branch is here
	 * rather than inside the service behind a flag. `dryRun: true` lost
	 * anywhere in the middle of a chain is a migration nobody asked for, and
	 * the answer still carries the caller's own `dryRun` back, so it reads
	 * like the preview they wanted.
	 *
	 * @param string                $uuid     The run.
	 * @param string                $actorUid Who asked.
	 * @param array<string, string> $mapping  Old node id to new node id.
	 *
	 * @return array<string, mixed> What the service answered.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	private function outcomeFor(string $uuid, string $actorUid, array $mapping): array {
		$targetVersion = (int)$this->request->getParam('targetVersion', 0);
		$reason = (string)$this->request->getParam('reason', '');

		if ($this->request->getParam('dryRun', false) === true) {
			return $this->migrations->preview(
				runUuid: $uuid,
				targetVersion: $targetVersion,
				reason: $reason,
				actor: $actorUid,
				mapping: $mapping,
			);
		}

		return $this->migrations->migrate(
			runUuid: $uuid,
			targetVersion: $targetVersion,
			reason: $reason,
			actor: $actorUid,
			mapping: $mapping,
		);
	}//end outcomeFor()

}//end class
