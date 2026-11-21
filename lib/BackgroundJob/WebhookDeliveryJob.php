<?php

/**
 * OpenRegister Webhook Delivery Job
 *
 * Background job for webhook delivery with retries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-webhook-delivery-must-support-async-processing-via-background-jobs
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Service\WebhookDelivery;
use OCA\OpenRegister\Service\WebhookEvent;
use OCA\OpenRegister\Service\WebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job for webhook delivery with retries
 *
 * Handles asynchronous webhook delivery, particularly for retries after failed
 * delivery attempts. Implements exponential backoff and retry logic for reliable
 * webhook delivery.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */
class WebhookDeliveryJob extends QueuedJob {

	/**
	 * Webhook mapper
	 *
	 * Handles database operations for webhook entities.
	 *
	 * @var WebhookMapperInterface Webhook mapper instance
	 */
	private readonly WebhookMapperInterface $webhookMapper;

	/**
	 * Webhook service
	 *
	 * Handles webhook delivery logic and HTTP requests.
	 *
	 * @var WebhookService Webhook service instance
	 */
	private readonly WebhookService $webhookService;

	/**
	 * Logger
	 *
	 * Used for logging delivery attempts, successes, and errors.
	 *
	 * @var LoggerInterface Logger instance
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor
	 *
	 * Initializes background job with required dependencies for webhook delivery.
	 * Calls parent constructor to set up base job functionality with time factory.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling
	 * @param WebhookMapperInterface $webhookMapper Webhook mapper for database operations
	 * @param WebhookService $webhookService Webhook service for delivery logic
	 * @param LoggerInterface $logger Logger for error tracking
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		WebhookMapperInterface $webhookMapper,
		WebhookService $webhookService,
		LoggerInterface $logger,
	) {
		// Call parent constructor to initialize base job with time factory.
		parent::__construct(time: $time);

		// Store dependencies for use in job execution.
		$this->webhookMapper = $webhookMapper;
		$this->webhookService = $webhookService;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Run the background job
	 *
	 * Executes webhook delivery with retry logic. Extracts webhook configuration,
	 * delivers payload to webhook URL, and handles retries on failure.
	 *
	 * @param array<string, mixed> $argument Job arguments containing:
	 *                                       - webhook_id: Webhook ID to deliver (required)
	 *                                       - event_name: Event class name (required)
	 *                                       - payload: Event payload data (required)
	 *                                       - attempt: Current attempt number (default: 1)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-webhook-delivery-must-support-async-processing-via-background-jobs
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	protected function run($argument): void {
		if (($argument['webhook_id'] ?? null) === null || ($argument['event_name'] ?? null) === null) {
			$this->logInvalidArguments(argument: $argument);
			return;
		}

		try {
			$this->deliverFromArgument(argument: $argument);
		} catch (\Exception $e) {
			$this->logDeliveryException(argument: $argument, error: $e);
		}
	}//end run()

	/**
	 * Log that the job was queued without a webhook id or event name.
	 *
	 * @param mixed $argument The job arguments as queued
	 *
	 * @return void
	 */
	private function logInvalidArguments(mixed $argument): void {
		$this->logger->error(
			message: '[WebhookDeliveryJob] WebhookDeliveryJob called with invalid arguments',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'argument' => $argument,
			]
		);
	}//end logInvalidArguments()

	/**
	 * Look the webhook up and deliver the job's event to it, logging each step.
	 *
	 * @param array<string, mixed> $argument Validated job arguments
	 *
	 * @return void
	 */
	private function deliverFromArgument(array $argument): void {
		$webhook = $this->webhookMapper->find($argument['webhook_id']);

		$this->logger->info(
			message: '[WebhookDeliveryJob] Executing webhook delivery job',
			context: $this->deliveryContext(argument: $argument, webhook: $webhook)
		);

		$success = $this->webhookService->deliverWebhook($this->deliveryFor(argument: $argument, webhook: $webhook));

		$this->logDeliveryOutcome(
			success: $success,
			context: $this->deliveryContext(argument: $argument, webhook: $webhook)
		);
	}//end deliverFromArgument()

	/**
	 * Build the delivery for the job's event and attempt.
	 *
	 * @param array<string, mixed> $argument Validated job arguments
	 * @param Webhook              $webhook  The webhook to deliver to
	 *
	 * @return WebhookDelivery
	 */
	private function deliveryFor(array $argument, Webhook $webhook): WebhookDelivery {
		return new WebhookDelivery(
			webhook: $webhook,
			event: new WebhookEvent(name: $argument['event_name'], payload: $argument['payload'] ?? []),
			attempt: $argument['attempt'] ?? 1
		);
	}//end deliveryFor()

	/**
	 * Log whether the delivery succeeded or failed.
	 *
	 * @param bool                 $success Whether the delivery succeeded
	 * @param array<string, mixed> $context The delivery log context
	 *
	 * @return void
	 */
	private function logDeliveryOutcome(bool $success, array $context): void {
		if ($success === true) {
			$this->logger->info(
				message: '[WebhookDeliveryJob] Webhook delivery job completed successfully',
				context: $context
			);
		}

		if ($success === false) {
			$this->logger->warning(
				message: '[WebhookDeliveryJob] Webhook delivery job failed',
				context: $context
			);
		}
	}//end logDeliveryOutcome()

	/**
	 * Log context shared by the delivery messages.
	 *
	 * @param array<string, mixed> $argument Validated job arguments
	 * @param Webhook              $webhook  The webhook delivered to
	 *
	 * @return array<string, mixed>
	 */
	private function deliveryContext(array $argument, Webhook $webhook): array {
		return [
			'file' => __FILE__,
			'line' => __LINE__,
			'webhook_id' => $argument['webhook_id'],
			'webhook_name' => $webhook->getName(),
			'event' => $argument['event_name'],
			'attempt' => $argument['attempt'] ?? 1,
		];
	}//end deliveryContext()

	/**
	 * Log an exception raised while delivering.
	 *
	 * @param array<string, mixed> $argument Validated job arguments
	 * @param \Exception           $error    The exception raised
	 *
	 * @return void
	 */
	private function logDeliveryException(array $argument, \Exception $error): void {
		$this->logger->error(
			message: '[WebhookDeliveryJob] Webhook delivery job encountered an exception',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'webhook_id' => $argument['webhook_id'],
				'event' => $argument['event_name'],
				'attempt' => $argument['attempt'] ?? 1,
				'error' => $error->getMessage(),
				'trace' => $error->getTraceAsString(),
			]
		);
	}//end logDeliveryException()
}//end class
