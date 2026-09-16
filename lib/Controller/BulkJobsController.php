<?php

/**
 * OpenRegister Bulk Jobs Controller
 *
 * The HTTP surface of a bulk action job: the action catalogue, creating a
 * job (which rehearses it), reading its progress and its per-object
 * outcomes, downloading the outcome, committing, cancelling, retrying and
 * undoing.
 *
 * Every route is owner-scoped. A user reads their own jobs; an administrator
 * may read any.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\BulkJob\BulkJobReversal;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * BulkJobsController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A REST surface over the
 * job record, the action catalogue and the lifecycle service.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Ten endpoints over one
 * resource. Splitting them across controllers to move the number under the
 * threshold would put the same ownership check in two places, which is the
 * failure the threshold exists to prevent.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) The same ten endpoints. Every
 * public method here is one route, and the count is the size of the resource
 * rather than a sign the class does two things.
 *
 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
 */
class BulkJobsController extends Controller {

	/**
	 * The largest page of members one request returns.
	 *
	 * @var int
	 */
	private const MAX_PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param string $appName Application name.
	 * @param IRequest $request HTTP request.
	 * @param BulkJobService $service The lifecycle service.
	 * @param BulkJobReversal $reversal The inverse of a reversible job.
	 * @param BulkJobMapper $jobMapper Job persistence.
	 * @param BulkActionRegistry $registry The action catalogue.
	 * @param IUserSession $userSession Current-user session.
	 * @param IGroupManager $groupManager Group manager (admin check).
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor injection.
	 * Each is a collaborator this REST surface genuinely uses.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BulkJobService $service,
		private readonly BulkJobReversal $reversal,
		private readonly BulkJobMapper $jobMapper,
		private readonly BulkActionRegistry $registry,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The bulk actions available on this instance.
	 *
	 * @return JSONResponse The catalogue.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function actions(): JSONResponse {
		if ($this->currentUid() === null) {
			return $this->authRequired();
		}

		return new JSONResponse(
			data: [
				'results' => $this->registry->describe(),
				'ceiling' => $this->service->getCeiling(),
				'undoCeiling' => $this->service->getUndoCeiling(),
			]
		);
	}//end actions()

	/**
	 * The caller's own jobs, running and finished.
	 *
	 * @return JSONResponse The jobs.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		$uid = $this->currentUid();
		if ($uid === null) {
			return $this->authRequired();
		}

		$state = $this->request->getParam('state');
		$limit = $this->pageSize(value: $this->request->getParam('limit'), fallback: 50);
		$offset = $this->pageOffset(value: $this->request->getParam('offset'));

		$wantsAll = filter_var($this->request->getParam('all', 'false'), FILTER_VALIDATE_BOOLEAN);

		// Two arms, each running exactly one query, and an early return
		// rather than an else so neither arm can fall into the other.
		if ($wantsAll === true && $this->isAdmin() === true) {
			return $this->jobList(
				jobs: $this->jobMapper->findAllJobs(state: $state, limit: $limit, offset: $offset)
			);
		}

		return $this->jobList(
			jobs: $this->jobMapper->findByActor(startedBy: $uid, state: $state, limit: $limit, offset: $offset)
		);
	}//end index()

	/**
	 * Serialise a page of jobs.
	 *
	 * @param BulkJob[] $jobs The jobs.
	 *
	 * @return JSONResponse The listing.
	 */
	private function jobList(array $jobs): JSONResponse {
		return new JSONResponse(
			data: ['results' => array_map(static fn (BulkJob $job): array => $job->jsonSerialize(), $jobs)]
		);
	}//end jobList()

	/**
	 * One job, with its progress and its counts.
	 *
	 * @param int $id The job id.
	 *
	 * @return JSONResponse The job.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): JSONResponse {
		$job = $this->readable(id: $id);

		if ($job instanceof JSONResponse) {
			return $job;
		}

		return new JSONResponse(data: $job->jsonSerialize());
	}//end show()

	/**
	 * Create a job. This rehearses the action and writes nothing.
	 *
	 * @return JSONResponse The previewed job with its counts.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$uid = $this->currentUid();
		if ($uid === null) {
			return $this->authRequired();
		}

		$action = (string)$this->request->getParam('action', '');
		$parameters = $this->arrayParam(name: 'parameters');
		$selection = $this->arrayParam(name: 'selection');
		$justification = $this->nullableString(value: $this->request->getParam('justification'));
		$register = $this->nullableInt(value: $this->request->getParam('register'));
		$schema = $this->nullableInt(value: $this->request->getParam('schema'));

		try {
			$job = $this->service->create(
				actionId: $action,
				parameters: $parameters,
				selection: $selection,
				justification: $justification,
				actorUid: $uid,
				registerId: $register,
				schemaId: $schema
			);
		} catch (BulkJobRefusedException $exception) {
			return $this->refusal(exception: $exception);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 400);
		}//end try

		return new JSONResponse(data: $job->jsonSerialize(), statusCode: 201);
	}//end create()

	/**
	 * Commit a previewed job.
	 *
	 * @param int $id The job id.
	 *
	 * @return JSONResponse The running job.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	#[NoAdminRequired]
	public function commit(int $id): JSONResponse {
		$job = $this->readable(id: $id);

		if ($job instanceof JSONResponse) {
			return $job;
		}

		if ($job->getState() !== BulkJob::STATE_PREVIEWED) {
			return new JSONResponse(
				data: [
					'error' => 'Only a previewed job can be committed. This one is '.$job->getState().'.',
					'state' => $job->getState(),
				],
				statusCode: 409
			);
		}

		$justification = $this->nullableString(value: $this->request->getParam('justification'));

		try {
			$committed = $this->service->commit(job: $job, justification: $justification);
		} catch (BulkJobRefusedException $exception) {
			return $this->refusal(exception: $exception);
		}

		return new JSONResponse(data: $committed->jsonSerialize(), statusCode: 202);
	}//end commit()

	/**
	 * Ask a job to stop before its next object.
	 *
	 * @param int $id The job id.
	 *
	 * @return JSONResponse The job.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	#[NoAdminRequired]
	public function cancel(int $id): JSONResponse {
		$job = $this->readable(id: $id);

		if ($job instanceof JSONResponse) {
			return $job;
		}

		return new JSONResponse(data: $this->service->cancel(job: $job)->jsonSerialize());
	}//end cancel()

	/**
	 * Retry a job that stopped part way.
	 *
	 * @param int $id The job id.
	 *
	 * @return JSONResponse The running job.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	#[NoAdminRequired]
	public function retry(int $id): JSONResponse {
		$job = $this->readable(id: $id);

		if ($job instanceof JSONResponse) {
			return $job;
		}

		try {
			$retried = $this->service->retry(job: $job);
		} catch (BulkJobRefusedException $exception) {
			return $this->refusal(exception: $exception);
		}

		return new JSONResponse(data: $retried->jsonSerialize(), statusCode: 202);
	}//end retry()

	/**
	 * Undo a job: create the reversal that writes its prior values back.
	 *
	 * The reversal is returned PREVIEWED, like any other job. The caller reads
	 * which members it would restore and which it would leave alone, then
	 * commits it. A reversal that committed itself would be a bulk write with
	 * no preview, which is the shape this whole capability exists to replace.
	 *
	 * @param int $id The id of the job to undo.
	 *
	 * @return JSONResponse The previewed reversal, or the refusal.
	 *
	 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
	 */
	#[NoAdminRequired]
	public function reverse(int $id): JSONResponse {
		$job = $this->readable(id: $id);

		if ($job instanceof JSONResponse) {
			return $job;
		}

		$uid = $this->currentUid();

		if ($uid === null) {
			return $this->authRequired();
		}

		$justification = $this->nullableString(value: $this->request->getParam('justification'));

		try {
			$created = $this->reversal->reverse(original: $job, actorUid: $uid, justification: $justification);
		} catch (BulkJobRefusedException $exception) {
			return $this->refusal(exception: $exception);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 400);
		}

		return new JSONResponse(data: $created->jsonSerialize(), statusCode: 201);
	}//end reverse()

	/**
	 * A page of the job's per-object outcomes.
	 *
	 * @param int $id The job id.
	 *
	 * @return JSONResponse The members.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function members(int $id): JSONResponse {
		$job = $this->readable(id: $id);

		if ($job instanceof JSONResponse) {
			return $job;
		}

		$outcome = $this->nullableString(value: $this->request->getParam('outcome'));
		$limit = $this->pageSize(value: $this->request->getParam('limit'), fallback: 100);
		$offset = $this->pageOffset(value: $this->request->getParam('offset'));

		$members = $this->service->members(
			job: $job,
			outcome: $outcome,
			limit: $limit,
			offset: $offset
		);

		return new JSONResponse(
			data: [
				'results' => array_map(static fn (BulkJobMember $member): array => $member->jsonSerialize(), $members),
				'total' => $job->getTotal(),
			]
		);
	}//end members()

	/**
	 * The whole outcome as a file, skipped members and reasons included.
	 *
	 * @param int $id The job id.
	 *
	 * @return DataDownloadResponse|JSONResponse The outcome report.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function download(int $id): DataDownloadResponse | JSONResponse {
		$job = $this->readable(id: $id);

		if ($job instanceof JSONResponse) {
			return $job;
		}

		$rows = ["object,outcome,reason,schema version,added at commit"];
		$offset = 0;
		$more = true;

		while ($more === true) {
			$page = $this->service->members(job: $job, limit: self::MAX_PAGE, offset: $offset);

			// A short page is the last page, so the next request is not made.
			$more = (count($page) === self::MAX_PAGE);

			foreach ($page as $member) {
				$grown = 'no';
				if ($member->getAddedAtCommit() === true) {
					$grown = 'yes';
				}

				$rows[] = implode(
					',',
					[
						$this->csvCell(value: $member->getObjectUuid()),
						$this->csvCell(value: $member->getOutcome()),
						$this->csvCell(value: $member->getReason()),
						$this->csvCell(value: $member->getSchemaVersion()),
						$this->csvCell(value: $grown),
					]
				);
			}

			$offset += self::MAX_PAGE;
		}//end while

		return new DataDownloadResponse(
			data: implode("\n", $rows)."\n",
			filename: 'bulk-job-'.$job->getUuid().'.csv',
			contentType: 'text/csv'
		);
	}//end download()

	/**
	 * Load a job the caller may read, or the response that refuses them.
	 *
	 * @param int $id The job id.
	 *
	 * @return BulkJob|JSONResponse The job, or a refusal.
	 */
	private function readable(int $id): BulkJob | JSONResponse {
		$uid = $this->currentUid();

		if ($uid === null) {
			return $this->authRequired();
		}

		try {
			$job = $this->jobMapper->find($id);
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(data: ['error' => 'No such bulk job'], statusCode: 404);
		}

		if ($job->getStartedBy() !== $uid && $this->isAdmin() === false) {
			// Not 403: a job the caller may not read should not be
			// distinguishable from one that does not exist.
			return new JSONResponse(data: ['error' => 'No such bulk job'], statusCode: 404);
		}

		return $job;
	}//end readable()

	/**
	 * The response for a refused job.
	 *
	 * @param BulkJobRefusedException $exception The refusal.
	 *
	 * @return JSONResponse The 422.
	 */
	private function refusal(BulkJobRefusedException $exception): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => $exception->getMessage(),
				'reason' => $exception->getReason(),
				'details' => $exception->getDetails(),
			],
			statusCode: 422
		);
	}//end refusal()

	/**
	 * The current user's uid, or null when anonymous.
	 *
	 * @return string|null The uid.
	 */
	private function currentUid(): ?string {
		$user = $this->userSession->getUser();

		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end currentUid()

	/**
	 * Whether the caller is a Nextcloud administrator.
	 *
	 * @return bool True for an administrator.
	 */
	private function isAdmin(): bool {
		$uid = $this->currentUid();

		if ($uid === null) {
			return false;
		}

		return $this->groupManager->isAdmin($uid);
	}//end isAdmin()

	/**
	 * The 401 for an anonymous caller.
	 *
	 * @return JSONResponse The 401.
	 */
	private function authRequired(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
	}//end authRequired()

	/**
	 * Read an array-shaped request parameter.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return array<string, mixed> The parameter, or an empty array.
	 */
	private function arrayParam(string $name): array {
		$value = $this->request->getParam($name);

		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end arrayParam()

	/**
	 * Read a page-size parameter, bounded by the largest page.
	 *
	 * @param mixed $value The raw parameter.
	 * @param int $fallback The value to use when none was sent.
	 *
	 * @return int The page size.
	 */
	private function pageSize(mixed $value, int $fallback): int {
		if ($value === null || is_numeric($value) === false) {
			return $fallback;
		}

		return (int)max(1, min(self::MAX_PAGE, (int)$value));
	}//end pageSize()

	/**
	 * Read an offset parameter.
	 *
	 * Deliberately NOT bounded by the page size: a job may carry a thousand
	 * members, and capping the offset at one page would make the members
	 * beyond it unreachable while the route still answered 200.
	 *
	 * @param mixed $value The raw parameter.
	 *
	 * @return int The offset.
	 */
	private function pageOffset(mixed $value): int {
		if ($value === null || is_numeric($value) === false) {
			return 0;
		}

		return (int)max(0, (int)$value);
	}//end pageOffset()

	/**
	 * Read an optional string parameter.
	 *
	 * @param mixed $value The raw parameter.
	 *
	 * @return string|null The value, or null when none was sent.
	 */
	private function nullableString(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end nullableString()

	/**
	 * Read an optional numeric parameter.
	 *
	 * @param mixed $value The raw parameter.
	 *
	 * @return int|null The value, or null.
	 */
	private function nullableInt(mixed $value): ?int {
		if ($value === null || is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end nullableInt()

	/**
	 * Quote one cell of the outcome file.
	 *
	 * @param string|null $value The cell value.
	 *
	 * @return string The quoted cell.
	 */
	private function csvCell(?string $value): string {
		return '"'.str_replace('"', '""', (string)$value).'"';
	}//end csvCell()
}//end class
