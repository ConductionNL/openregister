<?php

/**
 * OpenRegister Transfer Execution Job
 *
 * Queued background job that executes one e-Depot transfer ATTEMPT for an
 * approved transfer list and orchestrates long-horizon cross-request retry
 * (archival-transfer-hardening, OR-AD-2): it loads the durable transfer
 * record, runs a single transport attempt via `EdepotTransferService`, and
 * on non-terminal failure re-enqueues itself with `attempt + 1` after an
 * exponential backoff (1 m → 8 h cap, ±10 % jitter) using the background-job
 * scheduler — never an in-process `sleep()`. After the attempt cap the
 * transfer is marked failed and the archivists are notified.
 *
 * Mirrors OR's shipped `WebhookDeliveryJob` argument convention
 * (`{..., attempt}`) and `WebhookRetryJob` durability semantics.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
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

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use OCA\OpenRegister\Service\Edepot\Transport\TransportInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Queued job executing one e-Depot transfer attempt with durable retry.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The job composes the transfer
 *   service, the durable record store, the three transports, the scheduler,
 *   and app-config — each a distinct collaborator in the attempt/retry flow.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 *   (Requirement: One job run performs one transport attempt per package)
 */
class TransferExecutionJob extends QueuedJob {

	/**
	 * Base backoff in seconds (attempt 1 → 60 s).
	 *
	 * @var int
	 */
	private const BASE_BACKOFF_SECONDS = 60;

	/**
	 * Backoff cap in seconds (8 hours).
	 *
	 * @var int
	 */
	private const MAX_BACKOFF_SECONDS = 28800;

	/**
	 * Default attempt cap (≈ 2 days of coverage) when unset in app-config.
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_ATTEMPTS = 10;

	/**
	 * App-config key for the attempt cap.
	 *
	 * @var string
	 */
	private const CONFIG_KEY_MAX_ATTEMPTS = 'edepot_transfer_max_attempts';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param EdepotTransferService $transferService The transfer service.
	 * @param TransferRecordService $transferRecordService Durable transfer-record persistence.
	 * @param TransferListService $transferListService Status machine + archivist escalation.
	 * @param SftpTransport $sftpTransport SFTP transport.
	 * @param RestApiTransport $restTransport REST API transport.
	 * @param OpenConnectorTransport $ocTransport OpenConnector transport.
	 * @param IJobList $jobList Background-job scheduler (re-enqueue).
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly EdepotTransferService $transferService,
		private readonly TransferRecordService $transferRecordService,
		private readonly TransferListService $transferListService,
		private readonly SftpTransport $sftpTransport,
		private readonly RestApiTransport $restTransport,
		private readonly OpenConnectorTransport $ocTransport,
		private readonly IJobList $jobList,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Execute one transfer attempt and schedule the next on non-terminal
	 * failure (or escalate at the attempt cap).
	 *
	 * @param mixed $argument Job argument: `{transferListId, attempt}`.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) The attempt/terminal/retry/escalate
	 *   branches are the durable-retry state machine; each is a required path.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 *   (Scenario: Failed attempt reschedules with backoff)
	 */
	protected function run(mixed $argument): void {
		if (is_array($argument) === false || empty($argument['transferListId']) === true) {
			$this->logger->error(
				message: '[TransferExecutionJob] Invalid job argument: missing transferListId',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		$transferListId = (string)$argument['transferListId'];
		$attempt = (int)($argument['attempt'] ?? 1);

		$list = $this->transferRecordService->loadTransferList($transferListId);
		if ($list === null) {
			$this->logger->error(
				message: '[TransferExecutionJob] Transfer list not found: ' . $transferListId,
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		try {
			$transport = $this->resolveTransport();
			$list = $this->transferService->executeAttempt(transferList: $list, transport: $transport, attempt: $attempt);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[TransferExecutionJob] Attempt threw: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'transferListId' => $transferListId, 'attempt' => $attempt]
			);
			// Treat an unexpected throw as a failed attempt for retry purposes.
			$list['status'] = TransferListService::STATUS_PARTIALLY_FAILED;
		}

		$status = (string)($list['status'] ?? TransferListService::STATUS_FAILED);

		if ($status === TransferListService::STATUS_COMPLETED) {
			$this->transferRecordService->saveTransferList($list);
			$this->logger->info(
				message: '[TransferExecutionJob] Transfer completed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'transferListId' => $transferListId, 'attempt' => $attempt]
			);
			return;
		}

		// Non-terminal failure: retry until the attempt cap, then escalate.
		$maxAttempts = $this->maxAttempts();
		if ($attempt >= $maxAttempts) {
			$list['status'] = TransferListService::STATUS_FAILED;
			$this->transferRecordService->saveTransferList($list);
			$this->transferListService->notifyArchivists($list);
			$this->logger->warning(
				message: '[TransferExecutionJob] Attempt cap reached — transfer failed, archivists notified',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'transferListId' => $transferListId,
					'attempt' => $attempt,
					'maxAttempts' => $maxAttempts,
				]
			);
			return;
		}

		// Keep the list non-terminal (in_progress) between attempts so a
		// partially-failed run keeps its confirmed objects while retrying.
		$list['status'] = TransferListService::STATUS_IN_PROGRESS;
		$this->transferRecordService->saveTransferList($list);

		$delay = $this->backoffSeconds(attempt: $attempt);
		$runAfter = ($this->time->getTime() + $delay);
		$this->jobList->scheduleAfter(
			TransferExecutionJob::class,
			$runAfter,
			[
				'transferListId' => $transferListId,
				'attempt' => ($attempt + 1),
			]
		);

		$this->logger->info(
			message: '[TransferExecutionJob] Attempt failed — rescheduled',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'transferListId' => $transferListId,
				'attempt' => $attempt,
				'nextAttempt' => ($attempt + 1),
				'delaySeconds' => $delay,
			]
		);

	}//end run()

	/**
	 * Exponential backoff with jitter: `min(60 * 2^(attempt-1), 28800)` ± 10 %.
	 *
	 * @param int $attempt The 1-based current attempt number.
	 *
	 * @return int The delay in seconds before the next attempt.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 *   (Scenario: Failed attempt reschedules with backoff)
	 */
	public function backoffSeconds(int $attempt): int {
		$exponent = max(0, ($attempt - 1));
		$base = (self::BASE_BACKOFF_SECONDS * (2 ** $exponent));
		$capped = min($base, self::MAX_BACKOFF_SECONDS);

		// ±10 % jitter to avoid synchronised retries (thundering herd).
		$jitterRange = (int)floor($capped * 0.10);
		$jitter = 0;
		if ($jitterRange > 0) {
			$jitter = random_int(($jitterRange * -1), $jitterRange);
		}

		return max(1, ($capped + $jitter));
	}//end backoffSeconds()

	/**
	 * The configured attempt cap (default 10).
	 *
	 * @return int The maximum number of attempts.
	 */
	private function maxAttempts(): int {
		$configured = (int)$this->appConfig->getValueString(
			'openregister',
			self::CONFIG_KEY_MAX_ATTEMPTS,
			(string)self::DEFAULT_MAX_ATTEMPTS
		);

		if ($configured < 1) {
			return self::DEFAULT_MAX_ATTEMPTS;
		}

		return $configured;
	}//end maxAttempts()

	/**
	 * Resolve the configured transport implementation.
	 *
	 * @return TransportInterface The transport to use.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 *   (Requirement: One job run performs one transport attempt per package)
	 */
	private function resolveTransport(): TransportInterface {
		$transportType = $this->appConfig->getValueString('openregister', 'edepot_transport', 'rest_api');

		return match ($transportType) {
			'sftp' => $this->sftpTransport,
			'openconnector' => $this->ocTransport,
			default => $this->restTransport,
		};

	}//end resolveTransport()
}//end class
