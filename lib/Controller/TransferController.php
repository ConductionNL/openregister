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

use OCA\OpenRegister\BackgroundJob\TransferExecutionJob;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
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
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly TransferRecordService $transferRecordService,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
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
