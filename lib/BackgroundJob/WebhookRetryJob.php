<?php

/**
 * OpenRegister Webhook Retry Job
 *
 * Cron job that processes failed webhook deliveries and retries them
 * based on their next_retry_at timestamp. Uses exponential backoff
 * with increasing intervals between retries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Cron
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\Support\WebhookDeliveryStores;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapperInterface;
use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Service\WebhookDelivery;
use OCA\OpenRegister\Service\WebhookEvent;
use OCA\OpenRegister\Service\WebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Webhook Retry Job
 *
 * Periodically checks for failed webhook deliveries that are ready for retry
 * and processes them using exponential backoff intervals.
 *
 * @category Cron
 * @package  OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @psalm-suppress UnusedClass
 */
class WebhookRetryJob extends TimedJob {
	/**
	 * Default interval: 5 minutes
	 */
	private const DEFAULT_INTERVAL = 300;

	/**
	 * Webhook mapper
	 *
	 * @var WebhookMapperInterface
	 */
	private WebhookMapperInterface $webhookMapper;

	/**
	 * Webhook log mapper
	 *
	 * @var WebhookLogMapperInterface
	 */
	private WebhookLogMapperInterface $webhookLogMapper;

	/**
	 * Webhook service
	 *
	 * @var WebhookService
	 */
	private WebhookService $webhookService;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor
	 *
	 * @param ITimeFactory $time Time factory
	 * @param WebhookDeliveryStores $stores The webhook and webhook log mappers
	 * @param WebhookService $webhookService Webhook service
	 * @param LoggerInterface $logger Logger
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		WebhookDeliveryStores $stores,
		WebhookService $webhookService,
		LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->webhookMapper = $stores->webhookMapper;
		$this->webhookLogMapper = $stores->webhookLogMapper;
		$this->webhookService = $webhookService;
		$this->logger = $logger;

		// Set interval to 5 minutes.
		$this->setInterval(seconds: self::DEFAULT_INTERVAL);
	}//end __construct()

	/**
	 * Run the retry job
	 *
	 * Finds failed webhook logs that are ready for retry and processes them.
	 *
	 * @param mixed $argument Job arguments (unused)
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	protected function run($argument): void {
		$now = new DateTime();
		$this->logCheckStarted(now: $now);

		// Find failed logs ready for retry.
		$failedLogs = $this->webhookLogMapper->findFailedForRetry($now);

		if (empty($failedLogs) === true) {
			$this->logNothingToRetry();
			return;
		}

		$this->logProcessingCount(count: count($failedLogs));

		foreach ($failedLogs as $log) {
			$this->retryLog(log: $log);
		}
	}//end run()

	/**
	 * Log that the job started looking for retries.
	 *
	 * @param DateTime $now The moment the job started
	 *
	 * @return void
	 */
	private function logCheckStarted(DateTime $now): void {
		$this->logger->debug(
			message: '[WebhookRetryJob] Checking for webhook retries',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'timestamp' => $now->format('c'),
			]
		);
	}//end logCheckStarted()

	/**
	 * Log that no failed delivery is due for a retry.
	 *
	 * @return void
	 */
	private function logNothingToRetry(): void {
		$this->logger->debug(
			message: '[WebhookRetryJob] No webhook retries needed',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end logNothingToRetry()

	/**
	 * Log how many failed deliveries are about to be processed.
	 *
	 * @param int $count Number of failed logs due for a retry
	 *
	 * @return void
	 */
	private function logProcessingCount(int $count): void {
		$this->logger->info(
			message: '[WebhookRetryJob] Processing webhook retries',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'count' => $count,
			]
		);
	}//end logProcessingCount()

	/**
	 * Retry one failed delivery, logging (never throwing) any error.
	 *
	 * @param WebhookLog $log The failed delivery log
	 *
	 * @return void
	 */
	private function retryLog(WebhookLog $log): void {
		try {
			// Call getWebhook(), not getWebhookId(): the log entity's column and
			// property are both named `webhook`, so the `Id` spelling reached
			// Entity::__call and threw "webhookId is not a valid attribute"
			// on every retry. The test doubled it with addMethods().
			$webhook = $this->webhookMapper->find($log->getWebhook());

			if ($this->isEnabledForRetry(webhook: $webhook, log: $log) === false
				|| $this->isWithinRetryLimit(webhook: $webhook, log: $log) === false
			) {
				return;
			}

			$this->deliverRetry(webhook: $webhook, log: $log);
		} catch (\Exception $e) {
			$this->logRetryError(log: $log, error: $e);
		}
	}//end retryLog()

	/**
	 * Check that the webhook is still enabled, logging a skip when it is not.
	 *
	 * @param Webhook    $webhook The webhook the log belongs to
	 * @param WebhookLog $log     The failed delivery log
	 *
	 * @return bool True when the webhook is enabled
	 */
	private function isEnabledForRetry(Webhook $webhook, WebhookLog $log): bool {
		if ($webhook->getEnabled() !== false) {
			return true;
		}

		$this->logger->debug(
			message: '[WebhookRetryJob] Skipping retry for disabled webhook',
			context: $this->retryContext(webhook: $webhook, log: $log)
		);
		return false;
	}//end isEnabledForRetry()

	/**
	 * Check that the log has retries left, logging a warning when it has not.
	 *
	 * @param Webhook    $webhook The webhook the log belongs to
	 * @param WebhookLog $log     The failed delivery log
	 *
	 * @return bool True when another attempt is allowed
	 */
	private function isWithinRetryLimit(Webhook $webhook, WebhookLog $log): bool {
		if ($log->getAttempt() < $webhook->getMaxRetries()) {
			return true;
		}

		$this->logger->warning(
			message: '[WebhookRetryJob] Webhook retry limit exceeded',
			context: [
				...$this->retryContext(webhook: $webhook, log: $log),
				'attempt' => $log->getAttempt(),
				'max_retries' => $webhook->getMaxRetries(),
			]
		);
		return false;
	}//end isWithinRetryLimit()

	/**
	 * Deliver the next attempt and log its outcome.
	 *
	 * @param Webhook    $webhook The webhook to deliver to
	 * @param WebhookLog $log     The failed delivery log
	 *
	 * @return void
	 */
	private function deliverRetry(Webhook $webhook, WebhookLog $log): void {
		$this->logger->info(
			message: '[WebhookRetryJob] Retrying webhook delivery',
			context: $this->attemptContext(webhook: $webhook, log: $log)
		);

		if ($this->sendRetry(webhook: $webhook, log: $log) === true) {
			$this->logRetrySucceeded(webhook: $webhook, log: $log);
			return;
		}

		$this->logger->warning(
			message: '[WebhookRetryJob] Webhook retry failed',
			context: $this->attemptContext(webhook: $webhook, log: $log)
		);
	}//end deliverRetry()

	/**
	 * Send the logged event to the webhook as the next attempt.
	 *
	 * @param Webhook    $webhook The webhook to deliver to
	 * @param WebhookLog $log     The failed delivery log
	 *
	 * @return bool True when the delivery succeeded
	 */
	private function sendRetry(Webhook $webhook, WebhookLog $log): bool {
		return $this->webhookService->deliverWebhook(
			new WebhookDelivery(
				webhook: $webhook,
				event: new WebhookEvent(name: $log->getEventClass(), payload: $log->getPayloadArray()),
				attempt: $log->getAttempt() + 1
			)
		);
	}//end sendRetry()

	/**
	 * Log that a retry succeeded.
	 *
	 * @param Webhook    $webhook The webhook delivered to
	 * @param WebhookLog $log     The failed delivery log
	 *
	 * @return void
	 */
	private function logRetrySucceeded(Webhook $webhook, WebhookLog $log): void {
		$this->logger->info(
			message: '[WebhookRetryJob] Webhook retry succeeded',
			context: $this->retryContext(webhook: $webhook, log: $log)
		);
	}//end logRetrySucceeded()

	/**
	 * Log an error raised while retrying one delivery.
	 *
	 * @param WebhookLog $log   The failed delivery log
	 * @param \Exception $error The error raised
	 *
	 * @return void
	 */
	private function logRetryError(WebhookLog $log, \Exception $error): void {
		$this->logger->error(
			message: '[WebhookRetryJob] Error processing webhook retry',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'log_id' => $log->getId(),
				'error' => $error->getMessage(),
			]
		);
	}//end logRetryError()

	/**
	 * Log context of an attempt: ids plus the attempt number being made.
	 *
	 * @param Webhook    $webhook The webhook delivered to
	 * @param WebhookLog $log     The failed delivery log
	 *
	 * @return array<string, mixed>
	 */
	private function attemptContext(Webhook $webhook, WebhookLog $log): array {
		return [
			...$this->retryContext(webhook: $webhook, log: $log),
			'attempt' => $log->getAttempt() + 1,
		];
	}//end attemptContext()

	/**
	 * Log context every per-log message starts with: source plus both ids.
	 *
	 * @param Webhook    $webhook The webhook the log belongs to
	 * @param WebhookLog $log     The failed delivery log
	 *
	 * @return array<string, mixed>
	 */
	private function retryContext(Webhook $webhook, WebhookLog $log): array {
		return [
			'file' => __FILE__,
			'line' => __LINE__,
			'webhook_id' => $webhook->getId(),
			'log_id' => $log->getId(),
		];
	}//end retryContext()
}//end class
