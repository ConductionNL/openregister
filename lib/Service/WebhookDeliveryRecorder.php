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
use OCA\OpenRegister\Db\WebhookMapperInterface;
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
	 * @param LoggerInterface        $logger           Logger
	 * @param WebhookMapperInterface $webhookMapper    Webhook statistics
	 * @param WebhookLogMapper       $webhookLogMapper Log entries
	 * @param Closure                $previewBody      (string $body, string $url): string, the log-safe preview of a response body
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly WebhookMapperInterface $webhookMapper,
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
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
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
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function recordResponse(WebhookDeliveryAttempt $delivery, array $response): bool {
		$statusCode = (int)$response['status_code'];
		if ($statusCode < 200 || $statusCode >= 300) {
			return $this->recordRejectedStatus(delivery: $delivery, response: $response);
		}

		$delivery->log->setSuccess(true);
		$delivery->log->setStatusCode($response['status_code']);
		$delivery->log->setResponseBody($response['body']);
		$this->logDelivered(delivery: $delivery, statusCode: $response['status_code']);
		$this->webhookMapper->updateStatistics(webhook: $delivery->webhook, success: true);
		$this->webhookLogMapper->insert($delivery->log);

		return true;
	}//end recordResponse()

	/**
	 * Log a successful delivery.
	 *
	 * @param WebhookDeliveryAttempt $delivery   The delivery
	 * @param mixed                  $statusCode The status code as sendRequest() returned it
	 *
	 * @return void
	 */
	private function logDelivered(WebhookDeliveryAttempt $delivery, mixed $statusCode): void {
		$context = $this->deliveryContext(delivery: $delivery);
		$context['status_code'] = $statusCode;
		$context['attempt'] = $delivery->log->getAttempt();
		$this->logger->info(message: '[WebhookService] Webhook delivered successfully', context: $context);
	}//end logDelivered()

	/**
	 * The log context every delivery log line starts with.
	 *
	 * @param WebhookDeliveryAttempt $delivery The delivery
	 *
	 * @return array File, line, webhook id and name, and event
	 */
	private function deliveryContext(WebhookDeliveryAttempt $delivery): array {
		return [
			'file' => __FILE__,
			'line' => __LINE__,
			'webhook_id' => $delivery->webhook->getId(),
			'webhook_name' => $delivery->webhook->getName(),
			'event' => $delivery->log->getEventClass(),
		];
	}//end deliveryContext()

	/**
	 * Append the attempt number and the retry limit to a log context.
	 *
	 * @param WebhookDeliveryAttempt $delivery The delivery
	 * @param array                  $context  The log context so far
	 *
	 * @return array The context with `attempt` and `max_retries`
	 */
	private function withAttempts(WebhookDeliveryAttempt $delivery, array $context): array {
		$context['attempt'] = $delivery->log->getAttempt();
		$context['max_retries'] = $delivery->webhook->getMaxRetries();

		return $context;
	}//end withAttempts()

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
		$webhookLog = $delivery->log;
		$webhookLog->setSuccess(false);
		$webhookLog->setStatusCode($statusCode);
		$webhookLog->setResponseBody($this->capResponseBody(body: (string)$response['body']));
		$webhookLog->setErrorMessage('Webhook endpoint returned non-2xx status: ' . $statusCode);
		$webhookLog->setRequestBody($webhookLog->getPayload());
		$context = $this->deliveryContext(delivery: $delivery);
		$context['status_code'] = $statusCode;
		$context = $this->withAttempts(delivery: $delivery, context: $context);
		$this->logger->error(message: '[WebhookService] Webhook delivery failed with non-2xx status', context: $context);

		return $this->finishFailedDelivery(delivery: $delivery);
	}//end recordRejectedStatus()

	/**
	 * Record a request that Guzzle failed (connection error, timeout or error response).
	 *
	 * @param WebhookDeliveryAttempt $delivery  The delivery
	 * @param RequestException       $exception The Guzzle failure
	 *
	 * @return bool Always false
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function recordRequestFailure(WebhookDeliveryAttempt $delivery, RequestException $exception): bool {
		$errorDetails = $this->requestErrorDetails(delivery: $delivery, exception: $exception);
		// The request body is stored only on failure, for the retry.
		$delivery->log->setRequestBody($delivery->log->getPayload());
		$delivery->log->setSuccess(false);

		$context = $this->deliveryContext(delivery: $delivery);
		$context['error'] = $delivery->log->getErrorMessage();
		$context['error_details'] = $errorDetails;
		$context = $this->withAttempts(delivery: $delivery, context: $context);
		$context = $this->withException(context: $context, exception: $exception);
		$this->logger->error(message: '[WebhookService] Webhook delivery failed', context: $context);

		return $this->finishFailedDelivery(delivery: $delivery);
	}//end recordRequestFailure()

	/**
	 * Append the exception class, code and trace to a log context.
	 *
	 * @param array            $context   The log context so far
	 * @param RequestException $exception The Guzzle failure
	 *
	 * @return array The context with `exception_class`, `exception_code` and `trace`
	 */
	private function withException(array $context, RequestException $exception): array {
		$context['exception_class'] = get_class($exception);
		$context['exception_code'] = $exception->getCode();
		$context['trace'] = $exception->getTraceAsString();

		return $context;
	}//end withException()

	/**
	 * The error details of a failed request; sets the error message, status and body on the log.
	 *
	 * @param WebhookDeliveryAttempt $delivery  The delivery
	 * @param RequestException       $exception The Guzzle failure
	 *
	 * @return array Connection or response details, then the request url, method and timeout
	 */
	private function requestErrorDetails(WebhookDeliveryAttempt $delivery, RequestException $exception): array {
		$delivery->log->setErrorMessage($exception->getMessage());
		$errorDetails = $this->connectionErrorDetails(exception: $exception);
		if ($exception->hasResponse() === true) {
			$errorDetails = $this->responseErrorDetails(delivery: $delivery, exception: $exception);
		}

		$errorDetails['request_url'] = $delivery->webhook->getUrl();
		$errorDetails['request_method'] = $delivery->webhook->getMethod();
		$errorDetails['timeout'] = $delivery->webhook->getTimeout();

		return $errorDetails;
	}//end requestErrorDetails()

	/**
	 * The error details of a request that got no response.
	 *
	 * @param RequestException $exception The Guzzle failure
	 *
	 * @return array `connection_error`, plus `error_code` when the failure carries one
	 */
	private function connectionErrorDetails(RequestException $exception): array {
		$errorDetails = ['connection_error' => true];
		if ($exception->getCode() !== 0) {
			$errorDetails['error_code'] = $exception->getCode();
		}

		return $errorDetails;
	}//end connectionErrorDetails()

	/**
	 * The error details of an error response; sets its status code on the log.
	 *
	 * @param WebhookDeliveryAttempt $delivery  The delivery
	 * @param RequestException       $exception The Guzzle failure, carrying a response
	 *
	 * @return array `status_code`, plus `response_body` when the body could be read
	 */
	private function responseErrorDetails(WebhookDeliveryAttempt $delivery, RequestException $exception): array {
		$statusCode = $exception->getResponse()->getStatusCode();
		$delivery->log->setStatusCode($statusCode);
		$errorDetails = ['status_code' => $statusCode];

		$preview = $this->recordErrorBody(delivery: $delivery, exception: $exception);
		if ($preview !== null) {
			$errorDetails['response_body'] = $preview;
		}

		return $errorDetails;
	}//end responseErrorDetails()

	/**
	 * Store the body of an error response on the log and extend the error message with it.
	 *
	 * The stored body is capped so a hostile endpoint cannot bloat log storage; the
	 * preview echoed into structured logs is shorter still and redacted for
	 * internal/private targets (see previewResponseBody()).
	 *
	 * @param WebhookDeliveryAttempt $delivery  The delivery
	 * @param RequestException       $exception The Guzzle failure, carrying a response
	 *
	 * @return string|null The log-safe body preview, or null when the body could not be read
	 */
	private function recordErrorBody(WebhookDeliveryAttempt $delivery, RequestException $exception): ?string {
		try {
			$responseBody = (string)$exception->getResponse()->getBody();
			$delivery->log->setResponseBody($this->capResponseBody(body: $responseBody));
			$preview = ($this->previewBody)($responseBody, $delivery->webhook->getUrl());
			$delivery->log->setErrorMessage($exception->getMessage() . $this->responseErrorSuffix(body: $responseBody));

			return $preview;
		} catch (\Exception) {
			// Ignore body reading errors.
			return null;
		}
	}//end recordErrorBody()

	/**
	 * The `message` or `error` of a JSON error response, as a suffix for the error message.
	 *
	 * @param string $body Response body
	 *
	 * @return string ": <message>", or an empty string when the body carries neither
	 */
	private function responseErrorSuffix(string $body): string {
		$jsonResponse = json_decode($body, true);
		if (is_array($jsonResponse) === false) {
			return '';
		}

		$detail = $jsonResponse['message'] ?? $jsonResponse['error'] ?? null;
		if ($detail === null) {
			return '';
		}

		return ': ' . $detail;
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
			$this->scheduleRetry(delivery: $delivery, attempt: $attempt + 1);
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
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function recordUnexpectedFailure(WebhookDeliveryAttempt $delivery, \Exception $exception): bool {
		$delivery->log->setSuccess(false);
		$delivery->log->setErrorMessage($exception->getMessage());
		$this->webhookLogMapper->insert($delivery->log);
		$this->logUnexpectedFailure(delivery: $delivery, exception: $exception);

		return false;
	}//end recordUnexpectedFailure()

	/**
	 * Log an unexpected error during delivery.
	 *
	 * @param WebhookDeliveryAttempt $delivery  The delivery
	 * @param \Exception             $exception The error
	 *
	 * @return void
	 */
	private function logUnexpectedFailure(WebhookDeliveryAttempt $delivery, \Exception $exception): void {
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
	}//end logUnexpectedFailure()

	/**
	 * Schedule retry for failed webhook delivery
	 *
	 * The retry is handled by the WebhookRetryJob cron job which runs every 5 minutes
	 * and checks for failed webhook logs with next_retry_at timestamps that have passed.
	 * The retry delay is stored in the webhook log's next_retry_at field using
	 * exponential backoff based on the webhook's retry policy.
	 *
	 * @param WebhookDeliveryAttempt $delivery The delivery being retried
	 * @param int                    $attempt  Next attempt number
	 *
	 * @return void
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#requirement-webhook-testing-must-support-dry-run-delivery
	 */
	private function scheduleRetry(WebhookDeliveryAttempt $delivery, int $attempt): void {
		$context = $this->deliveryContext(delivery: $delivery);
		$context['attempt'] = $attempt;
		$context['delay'] = $this->calculateRetryDelay(webhook: $delivery->webhook, attempt: $attempt);
		$this->logger->info(message: '[WebhookService] Scheduling webhook retry', context: $context);

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
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function capResponseBody(string $body): string {
		if (strlen($body) <= self::MAX_RESPONSE_BODY_BYTES) {
			return $body;
		}

		return substr($body, 0, self::MAX_RESPONSE_BODY_BYTES) . '... [truncated]';
	}//end capResponseBody()
}//end class
