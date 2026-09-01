<?php

/**
 * OpenRegister Transfer Controller
 *
 * Handles e-Depot transfer list operations. Serves the durable transfer
 * records persisted in the `edepot-transfers` system register (index/show)
 * and really dispatches an approved transfer to the queued execution path
 * (create) — the former placeholder responses + never-dispatched
 * `TransferExecutionJob` are the pre-existing stubs closed by
 * archival-transfer-hardening (OR-AD-2).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\TransferExecutionJob;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for e-Depot transfer operations.
 *
 * Provides endpoints for transfer list management: list, show, and initiate
 * (dispatch to the queued execution path) reading persisted transfer records.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */
class TransferController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param TransferRecordService $transferRecordService Durable transfer-record persistence.
	 * @param IJobList $jobList Background-job scheduler (dispatch execution).
	 * @param LoggerInterface $logger Logger.
	 * @param TransferListService $transferListService Approves and rejects transfer lists.
	 * @param IUserSession $userSession Names the archivist who decided.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly TransferRecordService $transferRecordService,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
		private readonly TransferListService $transferListService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * List all durable transfer lists.
	 *
	 * @return JSONResponse The list of transfer lists.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Scenario: Index returns persisted transfer lists)
	 */
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		$lists = $this->transferRecordService->listTransferLists();

		return new JSONResponse(data: ['results' => $lists, 'total' => count($lists)]);
	}//end index()

	/**
	 * Get a specific durable transfer list.
	 *
	 * @param string $id The transfer list UUID.
	 *
	 * @return JSONResponse The transfer list data, or a 404.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Scenario: Show returns a persisted transfer list)
	 */
	#[NoCSRFRequired]
	public function show(string $id): JSONResponse {
		$list = $this->transferRecordService->loadTransferList($id);
		if ($list === null) {
			return new JSONResponse(
				data: ['error' => "Transfer list '{$id}' not found"],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(data: $list);
	}//end show()

	/**
	 * Approve a transfer list, so it can be dispatched.
	 *
	 * 🔴 WITHOUT THIS THE WHOLE FLOW WAS UNREACHABLE. `create()` refuses to
	 * dispatch anything that is not `approved`, and nothing could set that
	 * status: `TransferListService::approveTransferList()` was implemented,
	 * specified, and had no caller anywhere in the fleet. A list could be built
	 * and then never moved. The service was even already imported here — the
	 * wiring was started and left unfinished.
	 *
	 * @param string $id The transfer list uuid.
	 *
	 * @return JSONResponse The approved list, 404, or 409 when it is not in review.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 */
	#[NoCSRFRequired]
	public function approve(string $id): JSONResponse {
		return $this->decide(id: $id, approve: true, reason: '');
	}//end approve()

	/**
	 * Reject a transfer list, with the reason the archivist gave.
	 *
	 * Unreachable for the same reason approve() was. A review that can only
	 * ever end in approval is not a review.
	 *
	 * @param string $id The transfer list uuid.
	 *
	 * @return JSONResponse The rejected list, 404, 400 without a reason, or 409.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 */
	#[NoCSRFRequired]
	public function reject(string $id): JSONResponse {
		$reason = trim((string)($this->request->getParam('reason', '')));
		if ($reason === '') {
			return new JSONResponse(
				data: ['error' => 'A rejection needs a reason.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return $this->decide(id: $id, approve: false, reason: $reason);
	}//end reject()

	/**
	 * Record an archivist's decision on a transfer list.
	 *
	 * Both decisions share their whole shape — load, refuse if absent, refuse
	 * if the status forbids it, stamp who decided — so they share an
	 * implementation rather than two that drift.
	 *
	 * The `InvalidArgumentException` the service raises is a REFUSAL, not a
	 * fault: it means the list is not in review, which is a state the caller
	 * can see and act on. It answers 409, never 500.
	 *
	 * @param string  $id      The transfer list uuid.
	 * @param boolean $approve Whether this is an approval.
	 * @param string  $reason  The rejection reason; ignored for an approval.
	 *
	 * @return JSONResponse The updated list, or the refusal.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 */
	private function decide(string $id, bool $approve, string $reason): JSONResponse {
		$list = $this->transferRecordService->loadTransferList($id);
		if ($list === null) {
			return new JSONResponse(
				data: ['error' => "Transfer list '{$id}' not found"],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$archivist = (string)($this->userSession->getUser()?->getUID() ?? '');

		try {
			if ($approve === true) {
				return new JSONResponse(
					data: $this->transferListService->approveTransferList(
						transferList: $list,
						archivistId: $archivist
					)
				);
			}

			return new JSONResponse(
				data: $this->transferListService->rejectTransferList(
					transferList: $list,
					archivistId: $archivist,
					reason: $reason
				)
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage(), 'reason' => 'wrong-status'],
				statusCode: Http::STATUS_CONFLICT
			);
		}//end try
	}//end decide()


	/**
	 * Initiate a transfer from an approved transfer list.
	 *
	 * Loads the persisted list, verifies it is `approved` (client error
	 * otherwise — no enqueue), and dispatches `TransferExecutionJob` with
	 * `{transferListId, attempt: 1}` (the WebhookDeliveryJob argument
	 * convention). Retry / backoff / escalation are the job's concern.
	 *
	 * @return JSONResponse The transfer initiation result.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 *   (Scenario: Create dispatches an approved transfer)
	 */
	#[NoCSRFRequired]
	public function create(): JSONResponse {
		try {
			$params = $this->request->getParams();
			$transferListUuid = (string)($params['transferListUuid'] ?? '');

			if ($transferListUuid === '') {
				return new JSONResponse(
					data: ['error' => 'transferListUuid is required'],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			$list = $this->transferRecordService->loadTransferList($transferListUuid);
			if ($list === null) {
				return new JSONResponse(
					data: ['error' => "Transfer list '{$transferListUuid}' not found"],
					statusCode: Http::STATUS_NOT_FOUND
				);
			}

			$status = (string)($list['status'] ?? '');
			if ($status !== TransferListService::STATUS_APPROVED) {
				return new JSONResponse(
					data: [
						'error' => "Transfer list must be 'approved' to initiate; current status '{$status}'",
						'status' => $status,
					],
					statusCode: Http::STATUS_CONFLICT
				);
			}

			$this->jobList->add(
				TransferExecutionJob::class,
				[
					'transferListId' => $transferListUuid,
					'attempt' => 1,
				]
			);

			$this->logger->info(
				message: '[TransferController] Dispatched transfer execution',
				context: ['file' => __FILE__, 'line' => __LINE__, 'transferListUuid' => $transferListUuid]
			);

			return new JSONResponse(
				data: [
					'message' => 'Transfer queued for execution',
					'transferListUuid' => $transferListUuid,
					'status' => 'queued',
				],
				statusCode: Http::STATUS_ACCEPTED
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[TransferController] Failed to initiate transfer: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return new JSONResponse(
				data: ['error' => 'Failed to initiate transfer.'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

	}//end create()
}//end class
