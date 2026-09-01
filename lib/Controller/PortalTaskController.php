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

use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Exception\PortalSubjectException;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Portal\PortalSubject;
use OCA\OpenRegister\Service\Portal\PortalSubjectAssertion;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use OCA\OpenRegister\Service\Portal\PortalTaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
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
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param PortalSubjectAssertion $assertion Verifies the acting subject.
	 * @param PortalTaskService $portal The subject-scoped read and completion.
	 * @param PortalTaskDeliveryService $delivery The delivery ledger.
	 * @param IUserSession $userSession Names the operator on the delivery routes.
	 * @param IGroupManager|null $groupManager Decides administrator status;
	 *                                         absent, nobody is one.
	 * @param LoggerInterface|null $logger Where an unexpected failure's detail
	 *                                     goes, INSTEAD of the response.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalSubjectAssertion $assertion,
		private readonly PortalTaskService $portal,
		private readonly PortalTaskDeliveryService $delivery,
		private readonly IUserSession $userSession,
		private readonly ?IGroupManager $groupManager = null,
		private readonly ?LoggerInterface $logger = null,
	) {
		parent::__construct(appName: $appName, request: $request);

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
	public function index(int $limit = 25, int $offset = 0): JSONResponse {
		return $this->asSubject(
			fn (PortalSubject $subject): JSONResponse => new JSONResponse(
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
	public function show(string $uuid): JSONResponse {
		return $this->asSubject(
			fn (PortalSubject $subject): JSONResponse => new JSONResponse(
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
	public function complete(string $uuid, string $outcome = PortalTaskService::DEFAULT_OUTCOME, ?string $comment = null): JSONResponse {
		return $this->asSubject(
			fn (PortalSubject $subject): JSONResponse => new JSONResponse(
				$this->portal->row(
					task: $this->portal->complete(
						subject: $subject,
						uuid: $uuid,
						answers: $this->answers(),
						comment: $comment,
						files: $this->uploads(),
						outcome: $outcome
					)
				)
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
		$refusal = $this->refuseUnlessAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$rows = array_map(
			static fn (PortalTaskDelivery $row): array => $row->jsonSerialize(),
			$this->delivery->pending(limit: $limit)
		);

		return new JSONResponse(['results' => $rows, 'total' => count($rows)]);
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
		$refusal = $this->refuseUnlessAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return new JSONResponse($this->delivery->markDelivered(uuid: $uuid)->jsonSerialize());
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'No such delivery request', 'code' => 'no-such-delivery'], Http::STATUS_NOT_FOUND);
		}
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
		$refusal = $this->refuseUnlessAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		if (trim($error) === '') {
			return new JSONResponse(['error' => 'A failed delivery needs an error naming why.', 'code' => 'error-required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse($this->delivery->markFailed(uuid: $uuid, error: $error)->jsonSerialize());
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'No such delivery request', 'code' => 'no-such-delivery'], Http::STATUS_NOT_FOUND);
		}
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

			return new JSONResponse(['error' => 'No acting portal subject', 'code' => $refused->refusal()], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return $action($subject);
		} catch (TaskValidationException $refused) {
			return new JSONResponse(['error' => $refused->getMessage(), 'code' => 'upload-constraint'], Http::STATUS_BAD_REQUEST);
		} catch (TaskAccessDeniedException) {
			// Denied reads as absent: another subject who knows the uuid learns
			// nothing, not even that it exists. The denial is in the audit.
			return new JSONResponse(['error' => 'No such task', 'code' => 'no-such-task'], Http::STATUS_NOT_FOUND);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'No such task', 'code' => 'no-such-task'], Http::STATUS_NOT_FOUND);
		} catch (TaskConflictException $conflict) {
			return new JSONResponse(['error' => $conflict->getMessage(), 'code' => 'task-closed'], Http::STATUS_CONFLICT);
		} catch (Throwable $failure) {
			$this->logger?->error('[PortalTaskController] Portal task operation failed: ' . $failure->getMessage(), ['exception' => $failure]);

			return new JSONResponse(
				['error' => 'The portal task operation failed. The details are in the server log.', 'code' => 'internal'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end asSubject()

	/**
	 * The submitted answer fields: an object, or a JSON string of one.
	 *
	 * @return array<string, mixed> The answers; empty when none were sent.
	 */
	private function answers(): array {
		$raw = $this->request->getParam('answers');
		if (is_string($raw) === true) {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return [];
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
			$raw = $this->request->getUploadedFile($key);
			if (is_array($raw) === false || $raw === []) {
				continue;
			}

			$names = ($raw['name'] ?? null);
			if (is_array($names) === false) {
				$uploads[] = $this->oneUpload(raw: $raw);
				continue;
			}

			foreach (array_keys($names) as $index) {
				$uploads[] = $this->oneUpload(
					raw: [
						'name' => ($raw['name'][$index] ?? ''),
						'type' => ($raw['type'][$index] ?? ''),
						'tmp_name' => ($raw['tmp_name'][$index] ?? ''),
						'size' => ($raw['size'][$index] ?? 0),
						'error' => ($raw['error'][$index] ?? UPLOAD_ERR_NO_FILE),
					]
				);
			}
		}//end foreach

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
			return new JSONResponse(['error' => 'No session', 'code' => 'no-session'], Http::STATUS_UNAUTHORIZED);
		}

		$isAdmin = false;
		try {
			$isAdmin = ($this->groupManager?->isAdmin($user->getUID()) === true);
		} catch (Throwable) {
			$isAdmin = false;
		}

		if ($isAdmin === false) {
			return new JSONResponse(['error' => 'Administrators only', 'code' => 'admin-required'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refuseUnlessAdmin()
}//end class
