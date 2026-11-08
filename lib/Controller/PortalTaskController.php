<?php

/**
 * The portal seam's HTTP surface: a subject's tasks, one task, its
 * completion with uploads, and the delivery ledger portaliq settles.
 *
 * AUTH POSTURE, decided rather than inherited. The subject routes carry
 * `#[PublicPage]`: the caller is portaliq's server-to-server forward, which
 * has no Nextcloud session and MUST NOT need one. The check is the signed
 * `X-Portal-Subject` assertion, verified by {@see PortalSubjectAssertion}
 * fail-closed, and then the task service's own authorization of the acting
 * party against the task's STORED party reference. A Nextcloud session on the
 * request is ignored entirely: an administrator acting through this seam is
 * one of the callers the spec names as denied. The delivery routes are the
 * other way round: no assertion, an authenticated ADMINISTRATOR only, because
 * settling "this mail went out" is an operator's act inside the instance.
 *
 * CSRF: every mutating route carries `#[NoCSRFRequired]`, the same decision
 * `TaskController` records: these are driven server-to-server or with app
 * passwords, for which Nextcloud issues no CSRF token.
 *
 * REFUSALS, uniformly. 401 with a stable code when no acting subject can be
 * resolved; 404 for a task that is absent OR not this subject's (a stranger
 * who knows a uuid confirms nothing); 400 naming the violated upload
 * constraint; 409 when the task is already terminal; a LOGGED 500 with a
 * generic message otherwise.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Controller\Support\PortalTaskControllerServices;
use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Exception\PortalSubjectException;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskCompleteViaAppException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Portal\PortalSubject;
use OCA\OpenRegister\Service\Portal\PortalSubjectAssertion;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use OCA\OpenRegister\Service\Portal\PortalTaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * REST surface of the portal task seam.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The controller mediates
 * between HTTP and the seam's three services plus their exception shapes;
 * that is the whole of its job.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
 */
class PortalTaskController extends Controller {

	/**
	 * Verifies the acting subject.
	 *
	 * @var PortalSubjectAssertion
	 */
	private readonly PortalSubjectAssertion $assertion;

	/**
	 * The subject-scoped read and completion.
	 *
	 * @var PortalTaskService
	 */
	private readonly PortalTaskService $portal;

	/**
	 * The delivery ledger.
	 *
	 * @var PortalTaskDeliveryService
	 */
	private readonly PortalTaskDeliveryService $delivery;

	/**
	 * Names the operator on the delivery routes.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Decides administrator status;
	 *
	 * @var ?IGroupManager
	 */
	private readonly ?IGroupManager $groupManager;

	/**
	 * Where an unexpected failure's detail
	 *
	 * @var ?LoggerInterface
	 */
	private readonly ?LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param PortalTaskControllerServices $services The portal task controller services.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		PortalTaskControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->assertion = $services->domain->assertion;
		$this->portal = $services->domain->portal;
		$this->delivery = $services->domain->delivery;
		$this->userSession = $services->domain->userSession;
		$this->groupManager = $services->platform->groupManager;
		$this->logger = $services->platform->logger;

	}//end __construct()

	/**
	 * The acting subject's open portal tasks, with case context.
	 *
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return JSONResponse The page: results, total, limit, offset.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(int $limit = 25, int $offset = 0): JSONResponse {
		return $this->asSubject(
			action: fn (PortalSubject $subject): JSONResponse => new JSONResponse(
				$this->portal->listForSubject(subject: $subject, limit: $limit, offset: $offset)
			)
		);
	}//end index()

	/**
	 * One portal task, if it is the acting subject's.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The row; 404 when absent or not this subject's.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(string $uuid): JSONResponse {
		return $this->asSubject(
			action: fn (PortalSubject $subject): JSONResponse => new JSONResponse(
				$this->portal->row(task: $this->portal->show(subject: $subject, uuid: $uuid))
			)
		);
	}//end show()

	/**
	 * Complete a portal task as the acting subject, with answers and uploads.
	 *
	 * Multipart or JSON: files arrive under `files[]` or `file`; `answers` is
	 * an object (or a JSON string of one); `comment` and `outcome` are plain.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $outcome The outcome to record; `submitted` by default.
	 * @param string|null $comment The party's comment.
	 *
	 * @return JSONResponse The completed task row, or a named refusal.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function complete(string $uuid, string $outcome = PortalTaskService::DEFAULT_OUTCOME, ?string $comment = null): JSONResponse {
		return $this->asSubject(
			action: fn (PortalSubject $subject): JSONResponse => $this->completeAs(
				subject: $subject,
				uuid: $uuid,
				outcome: $outcome,
				comment: $comment
			)
		);
	}//end complete()

	/**
	 * The delivery requests awaiting settlement: what portaliq renders and sends.
	 *
	 * Administrator only. The route declares no NoAdminRequired, so the
	 * framework already refuses a non-administrator; the explicit check below
	 * makes the posture readable and holds if the attribute set ever changes.
	 *
	 * @param int $limit Page size.
	 *
	 * @return JSONResponse The pending rows.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	#[NoCSRFRequired]
	public function deliveries(int $limit = 100): JSONResponse {
		return ($this->refuseUnlessAdmin() ?? $this->pendingDeliveries(limit: $limit));
	}//end deliveries()

	/**
	 * Portaliq reports a delivery request as sent.
	 *
	 * @param string $uuid The delivery uuid.
	 *
	 * @return JSONResponse The settled row; 404 when no such request exists.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	#[NoCSRFRequired]
	public function deliveryDelivered(string $uuid): JSONResponse {
		return ($this->refuseUnlessAdmin() ?? $this->settled(mark: fn (): PortalTaskDelivery => $this->delivery->markDelivered(uuid: $uuid)));
	}//end deliveryDelivered()

	/**
	 * Portaliq reports a delivery request as failed, and why.
	 *
	 * @param string $uuid The delivery uuid.
	 * @param string $error The failure.
	 *
	 * @return JSONResponse The settled row; 404 when no such request exists.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	#[NoCSRFRequired]
	public function deliveryFailed(string $uuid, string $error = ''): JSONResponse {
		return ($this->refuseUnlessAdmin() ?? $this->settledAsFailed(uuid: $uuid, error: $error));
	}//end deliveryFailed()

	/**
	 * Resolve the acting subject, run the action as them, and translate every
	 * refusal to the wire, uniformly.
	 *
	 * @param callable(PortalSubject): JSONResponse $action The action.
	 *
	 * @return JSONResponse The action's response, or the refusal.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
	 */
	private function asSubject(callable $action): JSONResponse {
		try {
			$subject = $this->assertion->resolve(request: $this->request);
		} catch (PortalSubjectException $refused) {
			// No subject, no answer. The code says which class of defect; the
			// verifier's own message stays in the log.
			$this->logger?->info('[PortalTaskController] Refused a portal request: ' . $refused->getMessage(), ['code' => $refused->refusal()]);

			return $this->refused(error: 'No acting portal subject', code: $refused->refusal(), status: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return $action($subject);
		} catch (Throwable $failure) {
			return $this->translated(failure: $failure);
		}
	}//end asSubject()

	/**
	 * The submitted answer fields: an object, or a JSON string of one.
	 *
	 * @return array<string, mixed> The answers; empty when none were sent.
	 */
	private function answers(): array {
		$raw = $this->request->getParam('answers');
		if (is_string($raw) === true) {
			$raw = json_decode($raw, true);
		}

		if (is_array($raw) === true) {
			return $raw;
		}

		return [];
	}//end answers()

	/**
	 * The uploads, normalised to {name, type, size, tmp_name} regardless of
	 * whether they came as `files[]` or one `file`.
	 *
	 * @return array<int, array<string, mixed>> The uploads; empty when none.
	 */
	private function uploads(): array {
		$uploads = [];
		foreach (['files', 'file'] as $key) {
			$uploads = array_merge($uploads, $this->uploadsIn(raw: $this->request->getUploadedFile($key)));
		}

		// A slot that carried no file (UPLOAD_ERR_NO_FILE) is not an upload.
		return array_values(array_filter($uploads, static fn (array $file): bool => (int)$file['error'] === UPLOAD_ERR_OK));
	}//end uploads()

	/**
	 * One `$_FILES`-shaped entry, normalised.
	 *
	 * @param array<string, mixed> $raw The entry.
	 *
	 * @return array<string, mixed> {name, type, size, tmp_name, error}.
	 */
	private function oneUpload(array $raw): array {
		return [
			'name' => (string)($raw['name'] ?? ''),
			'type' => (string)($raw['type'] ?? ''),
			'size' => (int)($raw['size'] ?? 0),
			'tmp_name' => (string)($raw['tmp_name'] ?? ''),
			'error' => (int)($raw['error'] ?? UPLOAD_ERR_NO_FILE),
		];
	}//end oneUpload()

	/**
	 * Refuse the delivery routes to anyone but an authenticated administrator.
	 *
	 * @return JSONResponse|null The refusal, or null to proceed.
	 */
	private function refuseUnlessAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->refused(error: 'No session', code: 'no-session', status: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->isAdmin(user: $user) === false) {
			return $this->refused(error: 'Administrators only', code: 'admin-required', status: Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refuseUnlessAdmin()

	/**
	 * Whether the user is an administrator; false when the backend is absent or fails.
	 *
	 * @param IUser $user The session user.
	 *
	 * @return bool True only when the backend affirms it.
	 */
	private function isAdmin(IUser $user): bool {
		try {
			return ($this->groupManager?->isAdmin($user->getUID()) === true);
		} catch (Throwable) {
			return false;
		}
	}//end isAdmin()

	/**
	 * Complete the task as the subject, with the request's answers and uploads.
	 *
	 * @param PortalSubject $subject The acting subject.
	 * @param string        $uuid    The task uuid.
	 * @param string        $outcome The outcome to record.
	 * @param string|null   $comment The party's comment.
	 *
	 * @return JSONResponse The completed task row.
	 */
	private function completeAs(PortalSubject $subject, string $uuid, string $outcome, ?string $comment): JSONResponse {
		$task = $this->portal->complete(
			subject: $subject,
			uuid: $uuid,
			answers: $this->answers(),
			comment: $comment,
			files: $this->uploads(),
			outcome: $outcome
		);

		return new JSONResponse($this->portal->row(task: $task));
	}//end completeAs()

	/**
	 * The pending delivery rows.
	 *
	 * @param int $limit Page size.
	 *
	 * @return JSONResponse The rows and their count.
	 */
	private function pendingDeliveries(int $limit): JSONResponse {
		$rows = array_map(
			static fn (PortalTaskDelivery $row): array => $row->jsonSerialize(),
			$this->delivery->pending(limit: $limit)
		);

		return new JSONResponse(['results' => $rows, 'total' => count($rows)]);
	}//end pendingDeliveries()

	/**
	 * Settle a delivery as failed; the failure must name why.
	 *
	 * @param string $uuid  The delivery uuid.
	 * @param string $error The failure.
	 *
	 * @return JSONResponse The settled row, 400 without an error, 404 when unknown.
	 */
	private function settledAsFailed(string $uuid, string $error): JSONResponse {
		if (trim($error) === '') {
			return $this->refused(error: 'A failed delivery needs an error naming why.', code: 'error-required', status: Http::STATUS_BAD_REQUEST);
		}

		return $this->settled(mark: fn (): PortalTaskDelivery => $this->delivery->markFailed(uuid: $uuid, error: $error));
	}//end settledAsFailed()

	/**
	 * Run a settlement and answer the settled row, or 404 for an unknown request.
	 *
	 * @param callable(): PortalTaskDelivery $mark The settlement.
	 *
	 * @return JSONResponse The response.
	 */
	private function settled(callable $mark): JSONResponse {
		try {
			return new JSONResponse($mark()->jsonSerialize());
		} catch (DoesNotExistException) {
			return $this->refused(error: 'No such delivery request', code: 'no-such-delivery', status: Http::STATUS_NOT_FOUND);
		}
	}//end settled()

	/**
	 * Translate a failure of a subject action to the wire.
	 *
	 * The shapes are tried in order, so the more specific
	 * TaskCompleteViaAppException is matched before its parent
	 * TaskConflictException; anything unmatched is a logged, generic 500.
	 *
	 * @param Throwable $failure The failure.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function translated(Throwable $failure): JSONResponse {
		foreach ($this->refusalShapes() as $class => $shape) {
			if ($failure instanceof $class) {
				return $shape($failure);
			}
		}

		$this->logger?->error('[PortalTaskController] Portal task operation failed: ' . $failure->getMessage(), ['exception' => $failure]);

		return $this->refused(
			error: 'The portal task operation failed. The details are in the server log.',
			code: 'internal',
			status: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end translated()

	/**
	 * The named refusals, in matching order, keyed by exception class.
	 *
	 * Denied reads as absent: another subject who knows the uuid learns
	 * nothing, not even that it exists. The denial is in the audit.
	 *
	 * @return array<class-string<Throwable>, callable> The shapes.
	 */
	private function refusalShapes(): array {
		return [
			TaskValidationException::class => $this->uploadConstraint(...),
			TaskAccessDeniedException::class => $this->absentTask(...),
			DoesNotExistException::class => $this->absentTask(...),
			TaskCompleteViaAppException::class => $this->completeElsewhere(...),
			TaskConflictException::class => $this->taskClosed(...),
		];
	}//end refusalShapes()

	/**
	 * An upload or answer constraint the completion broke, named.
	 *
	 * @param TaskValidationException $refused The refusal.
	 *
	 * @return JSONResponse The 400.
	 */
	private function uploadConstraint(TaskValidationException $refused): JSONResponse {
		return $this->refused(error: $refused->getMessage(), code: 'upload-constraint', status: Http::STATUS_BAD_REQUEST);
	}//end uploadConstraint()

	/**
	 * An absent or denied task, indistinguishably.
	 *
	 * @return JSONResponse The 404.
	 */
	private function absentTask(): JSONResponse {
		return $this->refused(error: 'No such task', code: 'no-such-task', status: Http::STATUS_NOT_FOUND);
	}//end absentTask()

	/**
	 * A task already closed.
	 *
	 * @param TaskConflictException $conflict The refusal.
	 *
	 * @return JSONResponse The 409.
	 */
	private function taskClosed(TaskConflictException $conflict): JSONResponse {
		return $this->refused(error: $conflict->getMessage(), code: 'task-closed', status: Http::STATUS_CONFLICT);
	}//end taskClosed()

	/**
	 * The task names the app action that completes it: say which, so the
	 * portal opens that action instead of this form.
	 *
	 * @param TaskCompleteViaAppException $elsewhere The refusal.
	 *
	 * @return JSONResponse The 409.
	 */
	private function completeElsewhere(TaskCompleteViaAppException $elsewhere): JSONResponse {
		return new JSONResponse(
			['error' => $elsewhere->getMessage(), 'code' => 'complete-via-app', 'completeVia' => $elsewhere->completeVia()],
			Http::STATUS_CONFLICT
		);
	}//end completeElsewhere()

	/**
	 * A refusal body: the error and its machine-readable code.
	 *
	 * @param string $error  The message.
	 * @param string $code   The code.
	 * @param int    $status The status.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function refused(string $error, string $code, int $status): JSONResponse {
		return new JSONResponse(['error' => $error, 'code' => $code], $status);
	}//end refused()

	/**
	 * The uploads in one `$_FILES` slot: none, a single file, or a `files[]` list.
	 *
	 * @param mixed $raw The slot.
	 *
	 * @return array<int, array<string, mixed>> The normalised entries.
	 */
	private function uploadsIn(mixed $raw): array {
		if (is_array($raw) === false || $raw === []) {
			return [];
		}

		$names = ($raw['name'] ?? null);
		if (is_array($names) === false) {
			return [$this->oneUpload(raw: $raw)];
		}

		return array_map(
			fn (int|string $index): array => $this->oneUpload(raw: $this->uploadAt(raw: $raw, index: $index)),
			array_keys($names)
		);
	}//end uploadsIn()

	/**
	 * The entry at one index of a `files[]` slot.
	 *
	 * @param array<string, mixed> $raw   The slot.
	 * @param int|string           $index The index.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function uploadAt(array $raw, int|string $index): array {
		return [
			'name' => ($raw['name'][$index] ?? ''),
			'type' => ($raw['type'][$index] ?? ''),
			'tmp_name' => ($raw['tmp_name'][$index] ?? ''),
			'size' => ($raw['size'][$index] ?? 0),
			'error' => ($raw['error'][$index] ?? UPLOAD_ERR_NO_FILE),
		];
	}//end uploadAt()
}//end class
