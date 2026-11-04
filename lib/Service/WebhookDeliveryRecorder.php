<?php

/**
 * OpenRegister Webhook Delivery Recorder
 *
 * Records the outcome of a webhook delivery: its log entry, statistics and retry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Closure;
use DateTime;
use GuzzleHttp\Exception\RequestException;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use Psr\Log\LoggerInterface;

/**
 * Turns the answer to a webhook request into a saved log entry, delivery statistics and,
 * on failure, a retry time.
 */
final class WebhookDeliveryRecorder {

	/**
	 * Maximum response body size accepted from a webhook target, in bytes (1 MB).
	 *
	 * Larger bodies are truncated before being persisted in the webhook log to
	 * prevent log-storage exhaustion when a misconfigured endpoint returns a
	 * huge response.
	 *
	 * @var integer
	 */
	private const MAX_RESPONSE_BODY_BYTES = 1048576;

	/**
	 * @param LoggerInterface  $logger           Logger
	 * @param WebhookMapper    $webhookMapper    Webhook statistics
	 * @param WebhookLogMapper $webhookLogMapper Log entries
	 * @param Closure          $previewBody      (string $body, string $url): string, the log-safe preview of a response body
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly WebhookMapper $webhookMapper,
		private readonly WebhookLogMapper $webhookLogMapper,
		private readonly Closure $previewBody,
	) {
	}//end __construct()

	/**
	 * A new log entry for a delivery to the webhook's target.
	 *
	 * @param Webhook $webhook        Webhook configuration
	 * @param array   $webhookPayload The payload that is sent
	 *
	 * @return WebhookLog
	 */
	public function startDeliveryLog(Webhook $webhook, array $webhookPayload): WebhookLog {
		$webhookLog = new WebhookLog();
		$webhookLog->setWebhook($webhook->getId());
		$webhookLog->setPayloadArray($webhookPayload);
		$webhookLog->setUrl($webhook->getUrl());
		$webhookLog->setMethod($webhook->getMethod());

		return $webhookLog;
	}//end startDeliveryLog()

	/**
	 * Record the response of a sent request: a 2xx is a delivery, anything else a failure.
	 *
	 * The Guzzle client has http_errors disabled, so a 4xx/5xx response does not throw
	 * and is judged here.
	 *
	 * @param WebhookDeliveryAttempt $delivery The delivery
	 * @param array                  $response Status code and body from sendRequest()
	 *
	 * @return bool True when delivered
	 */
	public function recordResponse(WebhookDeliveryAttempt $delivery, array $response): bool {
		$statusCode = (int)$response['status_code'];
		if ($statusCode < 200 || $statusCode >= 300) {
			return $this->recordRejectedStatus(delivery: $delivery, response: $response);
		}

		$webhook = $delivery->webhook;
		$delivery->log->setSuccess(true);
		$delivery->log->setStatusCode($response['status_code']);
		$delivery->log->setResponseBody($response['body']);

		$this->logger->info(
			message: '[WebhookService] Webhook delivered successfully',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'webhook_id' => $webhook->getId(),
				'webhook_name' => $webhook->getName(),
				'event' => $delivery->log->getEventClass(),
				'status_code' => $response['status_code'],
				'attempt' => $delivery->log->getAttempt(),
			]
		);

		$this->webhookMapper->updateStatistics(webhook: $webhook, success: true);
		$this->webhookLogMapper->insert($delivery->log);

		return true;
	}//end recordResponse()

	/**
	 * Record a response with a non-2xx status as a failed delivery.
	 *
	 * @param WebhookDeliveryAttempt $delivery The delivery
	 * @param array                  $response Status code and body from sendRequest()
	 *
	 * @return bool Always false
	 */
	private function recordRejectedStatus(WebhookDeliveryAttempt $delivery, array $response): bool {
		$statusCode = (int)$response['status_code'];
		$webhook = $delivery->webhook;
		$webhookLog = $delivery->log;
		$webhookLog->setSuccess(false);
		$webhookLog->setStatusCode($statusCode);
		$webhookLog->setResponseBody($this->capResponseBody(body: (string)$response['body']));
		$webhookLog->setErrorMessage('Webhook endpoint returned non-2xx status: ' . $statusCode);
		$webhookLog->setRequestBody($webhookLog->getPayload());

		$this->logger->error(
			message: '[WebhookService] Webhook delivery failed with non-2xx status',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'webhook_id' => $webhook->getId(),
				'webhook_name' => $webhook->getName(),
				'event' => $webhookLog->getEventClass(),
				'status_code' => $statusCode,
				'attempt' => $webhookLog->getAttempt(),
				'max_retries' => $webhook->getMaxRetries(),
			]
		);

		return $this->finishFailedDelivery(delivery: $delivery);
	}//end recordRejectedStatus()

	/**
	 * Record a request that Guzzle failed (connection error, timeout or error response).
	 *
	 * @param WebhookDeliveryAttempt $delivery  The delivery
	 * @param RequestException       $exception The Guzzle failure
	 *
	 * @return bool Always false
	 */
	public function recordRequestFailure(WebhookDeliveryAttempt $delivery, RequestException $exception): bool {
		$webhook = $delivery->webhook;
		$webhookLog = $delivery->log;
		$errorDetails = $this->requestErrorDetails(delivery: $delivery, exception: $exception);
		$errorDetails['request_url'] = $webhook->getUrl();
		$errorDetails['request_method'] = $webhook->getMethod();
		$errorDetails['timeout'] = $webhook->getTimeout();

		// The request body is stored only on failure, for the retry.
		$webhookLog->setRequestBody($webhookLog->getPayload());
		$webhookLog->setSuccess(false);

		$this->logger->error(
			message: '[WebhookService] Webhook delivery failed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'webhook_id' => $webhook->getId(),
				'webhook_name' => $webhook->getName(),
				'event' => $webhookLog->getEventClass(),
				'error' => $webhookLog->getErrorMessage(),
				'error_details' => $errorDetails,
				'attempt' => $webhookLog->getAttempt(),
				'max_retries' => $webhook->getMaxRetries(),
				'exception_class' => get_class($exception),
				'exception_code' => $exception->getCode(),
				'trace' => $exception->getTraceAsString(),
			]
		);

		return $this->finishFailedDelivery(delivery: $delivery);
	}//end recordRequestFailure()

	/**
	 * The error details of a failed request; sets the error message, status and body on the log.
	 *
	 * @param WebhookDeliveryAttempt $delivery  The delivery
	 * @param RequestException       $exception The Guzzle failure
	 *
	 * @return array Connection error and code, or status code and a response body preview
	 */
	private function requestErrorDetails(WebhookDeliveryAttempt $delivery, RequestException $exception): array {
		$delivery->log->setErrorMessage($exception->getMessage());
		if ($exception->hasResponse() !== true) {
			$errorDetails = ['connection_error' => true];
			if ($exception->getCode() !== 0) {
				$errorDetails['error_code'] = $exception->getCode();
			}

			return $errorDetails;
		}

		$response = $exception->getResponse();
		$statusCode = $response->getStatusCode();
		$delivery->log->setStatusCode($statusCode);
		$errorDetails = ['status_code' => $statusCode];

		try {
			$responseBody = (string)$response->getBody();
			// The stored body is capped so a hostile endpoint cannot bloat log storage; the
			// preview echoed into structured logs is shorter still and redacted for
			// internal/private targets (see previewResponseBody()).
			$delivery->log->setResponseBody($this->capResponseBody(body: $responseBody));
			$errorDetails['response_body'] = ($this->previewBody)($responseBody, $delivery->webhook->getUrl());
			$delivery->log->setErrorMessage($exception->getMessage() . $this->responseErrorSuffix(body: $responseBody));
		} catch (\Exception $bodyException) {
			// Ignore body reading errors.
		}

		return $errorDetails;
	}//end requestErrorDetails()

	/**
	 * The `message` or `error` of a JSON error response, as a suffix for the error message.
	 *
	 * @param string $body Response body
	 *
	 * @return string ": <message>", or an empty string when the body carries neither
	 */
	private function responseErrorSuffix(string $body): string {
		$jsonResponse = json_decode($body, true);
		if ($jsonResponse !== null && (($jsonResponse['message'] ?? null) !== null)) {
			return ': ' . $jsonResponse['message'];
		}

		if ($jsonResponse !== null && (($jsonResponse['error'] ?? null) !== null)) {
			return ': ' . $jsonResponse['error'];
		}

		return '';
	}//end responseErrorSuffix()

	/**
	 * Close a failed delivery: count it, schedule a retry while retries remain, and save the log.
	 *
	 * @param WebhookDeliveryAttempt $delivery The delivery
	 *
	 * @return bool Always false
	 */
	private function finishFailedDelivery(WebhookDeliveryAttempt $delivery): bool {
		$webhook = $delivery->webhook;
		$attempt = $delivery->log->getAttempt();
		$this->webhookMapper->updateStatistics(webhook: $webhook, success: false);

		if ($attempt < $webhook->getMaxRetries()) {
			$delivery->log->setNextRetryAt($this->calculateNextRetryTime(webhook: $webhook, attempt: $attempt));
			$this->scheduleRetry(webhook: $webhook, eventName: $delivery->log->getEventClass(), attempt: $attempt + 1);
		}

		$this->webhookLogMapper->insert($delivery->log);

		return false;
	}//end finishFailedDelivery()

	/**
	 * Record an unexpected error during delivery.
	 *
	 * @param WebhookDeliveryAttempt $delivery  The delivery
	 * @param \Exception             $exception The error
	 *
	 * @return bool Always false
	 */
	public function recordUnexpectedFailure(WebhookDeliveryAttempt $delivery, \Exception $exception): bool {
		$delivery->log->setSuccess(false);
		$delivery->log->setErrorMessage($exception->getMessage());
		$this->webhookLogMapper->insert($delivery->log);

		$this->logger->error(
			message: '[WebhookService] Unexpected error during webhook delivery',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'webhook_id' => $delivery->webhook->getId(),
				'event' => $delivery->log->getEventClass(),
				'error' => $exception->getMessage(),
			]
		);

		return false;
	}//end recordUnexpectedFailure()

	/**
	 * Schedule retry for failed webhook delivery
	 *
	 * The retry is handled by the WebhookRetryJob cron job which runs every 5 minutes
	 * and checks for failed webhook logs with next_retry_at timestamps that have passed.
	 * The retry delay is stored in the webhook log's next_retry_at field using
	 * exponential backoff based on the webhook's retry policy.
	 *
	 * @param Webhook $webhook Webhook configuration
	 * @param string $eventName Event name
	 * @param int $attempt Next attempt number
	 *
	 * @return void
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-webhook-testing-must-support-dry-run-delivery
	 */
	private function scheduleRetry(Webhook $webhook, string $eventName, int $attempt): void {
		$delay = $this->calculateRetryDelay(webhook: $webhook, attempt: $attempt);

		$this->logger->info(
			message: '[WebhookService] Scheduling webhook retry',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'webhook_id' => $webhook->getId(),
				'webhook_name' => $webhook->getName(),
				'event' => $eventName,
				'attempt' => $attempt,
				'delay' => $delay,
			]
		);

		// Note: Retry is handled by WebhookRetryJob cron job.
		// The next_retry_at timestamp is already set in the webhook log entry.
		// No need to schedule a job here - the cron job will pick it up.
	}//end scheduleRetry()

	/**
	 * Calculate next retry timestamp
	 *
	 * @param Webhook $webhook Webhook configuration
	 * @param int $attempt Current attempt number
	 *
	 * @return DateTime Next retry timestamp
	 */
	private function calculateNextRetryTime(Webhook $webhook, int $attempt): DateTime {
		$delay = $this->calculateRetryDelay(webhook: $webhook, attempt: $attempt);
		$nextRetry = new DateTime();
		$nextRetry->modify('+' . $delay . ' seconds');

		return $nextRetry;
	}//end calculateNextRetryTime()

	/**
	 * Calculate retry delay based on retry policy
	 *
	 * @param Webhook $webhook Webhook configuration
	 * @param int $attempt Attempt number
	 *
	 * @return int Delay in seconds
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-webhook-testing-must-support-dry-run-delivery
	 */
	private function calculateRetryDelay(Webhook $webhook, int $attempt): int {
		switch ($webhook->getRetryPolicy()) {
			case 'exponential':
				// Exponential backoff: 2^attempt minutes.
				return pow(2, $attempt) * 60;
			case 'linear':
				// Linear backoff: attempt * 5 minutes.
				return $attempt * 300;
			case 'fixed':
				// Fixed delay: 5 minutes.
				return 300;
			default:
				return 300;
		}//end switch
	}//end calculateRetryDelay()

	/**
	 * Cap a response body to MAX_RESPONSE_BODY_BYTES for persistent storage.
	 *
	 * @param string $body Raw response body.
	 *
	 * @return string Body limited to the response-body cap.
	 */
	public function capResponseBody(string $body): string {
		if (strlen($body) <= self::MAX_RESPONSE_BODY_BYTES) {
			return $body;
		}

		return substr($body, 0, self::MAX_RESPONSE_BODY_BYTES) . '... [truncated]';
	}//end capResponseBody()
}//end class
