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

use OCA\OpenRegister\BackgroundJob\Support\TransferExecutionPlatform;
use OCA\OpenRegister\BackgroundJob\Support\TransferExecutionRecords;
use OCA\OpenRegister\BackgroundJob\Support\TransferExecutionTransports;
use OCA\OpenRegister\Service\Edepot\EdepotTransferAttemptInterface;
use OCA\OpenRegister\Service\Edepot\TransferListRunInterface;
use OCA\OpenRegister\Service\Edepot\TransferListServiceInterface;
use OCA\OpenRegister\Service\Edepot\TransferRecordStoreInterface;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransportInterface;
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
	 * The transfer service.
	 *
	 * @var EdepotTransferAttemptInterface
	 */
	private readonly EdepotTransferAttemptInterface $transferService;

	/**
	 * Durable transfer-record persistence.
	 *
	 * @var TransferRecordStoreInterface
	 */
	private readonly TransferRecordStoreInterface $transferRecordService;

	/**
	 * Status machine + archivist escalation.
	 *
	 * @var TransferListRunInterface
	 */
	private readonly TransferListRunInterface $transferListService;

	/**
	 * SFTP transport.
	 *
	 * @var SftpTransportInterface
	 */
	private readonly SftpTransportInterface $sftpTransport;

	/**
	 * REST API transport.
	 *
	 * @var RestApiTransportInterface
	 */
	private readonly RestApiTransportInterface $restTransport;

	/**
	 * OpenConnector transport.
	 *
	 * @var OpenConnectorTransportInterface
	 */
	private readonly OpenConnectorTransportInterface $ocTransport;

	/**
	 * Background-job scheduler (re-enqueue).
	 *
	 * @var IJobList
	 */
	private readonly IJobList $jobList;

	/**
	 * The app configuration.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                $time       The time factory.
	 * @param TransferExecutionRecords    $records    The transfer service and the transfer-list stores.
	 * @param TransferExecutionTransports $transports The SFTP, REST API and OpenConnector transports.
	 * @param TransferExecutionPlatform   $platform   Scheduler, app config and logger.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		TransferExecutionRecords $records,
		TransferExecutionTransports $transports,
		TransferExecutionPlatform $platform,
	) {
		parent::__construct(time: $time);
		$this->transferService = $records->transferService;
		$this->transferRecordService = $records->recordService;
		$this->transferListService = $records->listService;
		$this->sftpTransport = $transports->sftpTransport;
		$this->restTransport = $transports->restTransport;
		$this->ocTransport = $transports->ocTransport;
		$this->jobList = $platform->jobList;
		$this->appConfig = $platform->appConfig;
		$this->logger = $platform->logger;
	}//end __construct()

	/**
	 * Execute one transfer attempt and schedule the next on non-terminal
	 * failure (or escalate at the attempt cap).
	 *
	 * @param mixed $argument Job argument: `{transferListId, attempt}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 *   (Scenario: Failed attempt reschedules with backoff)
	 */
	protected function run(mixed $argument): void {
		$transferListId = $this->transferListIdOf(argument: $argument);
		if ($transferListId === null) {
			return;
		}

		$attempt = (int)($argument['attempt'] ?? 1);

		$list = $this->transferRecordService->loadTransferList($transferListId);
		if ($list === null) {
			$this->logger->error(
				message: '[TransferExecutionJob] Transfer list not found: ' . $transferListId,
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		$list = $this->attempt(list: $list, transferListId: $transferListId, attempt: $attempt);

		$this->settle(list: $list, transferListId: $transferListId, attempt: $attempt);
	}//end run()

	/**
	 * The transfer list id from the job argument, or null (logged) when it is missing.
	 *
	 * @param mixed $argument Job argument: `{transferListId, attempt}`.
	 *
	 * @return string|null The transfer list id.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 */
	private function transferListIdOf(mixed $argument): ?string {
		if (is_array($argument) === false || empty($argument['transferListId']) === true) {
			$this->logger->error(
				message: '[TransferExecutionJob] Invalid job argument: missing transferListId',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		return (string)$argument['transferListId'];
	}//end transferListIdOf()

	/**
	 * Run one attempt; an unexpected throw counts as a partially failed attempt.
	 *
	 * @param array<string, mixed> $list           The transfer list.
	 * @param string               $transferListId Its id, for the log.
	 * @param int                  $attempt        The attempt number.
	 *
	 * @return array<string, mixed> The list after the attempt.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 */
	private function attempt(array $list, string $transferListId, int $attempt): array {
		try {
			$transport = $this->resolveTransport();
			$list = $this->transferService->executeAttempt(transferList: $list, transport: $transport, attempt: $attempt);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[TransferExecutionJob] Attempt threw: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'transferListId' => $transferListId, 'attempt' => $attempt]
			);
			// Treat an unexpected throw as a failed attempt for retry purposes.
			$list['status'] = TransferListServiceInterface::STATUS_PARTIALLY_FAILED;
		}

		return $list;
	}//end attempt()

	/**
	 * Save a completed list, or retry / escalate a failed one.
	 *
	 * @param array<string, mixed> $list           The list after the attempt.
	 * @param string               $transferListId Its id.
	 * @param int                  $attempt        The attempt number.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 */
	private function settle(array $list, string $transferListId, int $attempt): void {
		$status = (string)($list['status'] ?? TransferListServiceInterface::STATUS_FAILED);

		if ($status === TransferListServiceInterface::STATUS_COMPLETED) {
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
			$this->escalate(list: $list, transferListId: $transferListId, attempt: $attempt, maxAttempts: $maxAttempts);
			return;
		}

		$this->reschedule(list: $list, transferListId: $transferListId, attempt: $attempt);
	}//end settle()

	/**
	 * Mark the list failed at the attempt cap and notify the archivists.
	 *
	 * @param array<string, mixed> $list           The list after the attempt.
	 * @param string               $transferListId Its id.
	 * @param int                  $attempt        The attempt number.
	 * @param int                  $maxAttempts    The attempt cap.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 */
	private function escalate(array $list, string $transferListId, int $attempt, int $maxAttempts): void {
		$list['status'] = TransferListServiceInterface::STATUS_FAILED;
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
	}//end escalate()

	/**
	 * Keep the list in progress and schedule the next attempt with backoff.
	 *
	 * @param array<string, mixed> $list           The list after the attempt.
	 * @param string               $transferListId Its id.
	 * @param int                  $attempt        The attempt number.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 */
	private function reschedule(array $list, string $transferListId, int $attempt): void {
		// Keep the list non-terminal (in_progress) between attempts so a
		// partially-failed run keeps its confirmed objects while retrying.
		$list['status'] = TransferListServiceInterface::STATUS_IN_PROGRESS;
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
	}//end reschedule()

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
