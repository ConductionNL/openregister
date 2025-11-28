<?php
/**
 * OpenRegister Webhook Service
 *
 * Service for handling webhook delivery.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use DateTime;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use Psr\Log\LoggerInterface;

/**
 * WebhookService handles webhook delivery
 */
class WebhookService
{

    /**
     * Webhook mapper
     *
     * @var WebhookMapper
     */
    private WebhookMapper $webhookMapper;

    /**
     * HTTP client
     *
     * @var Client
     */
    private Client $client;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Background job list
     *
     * @var IJobList
     */
    private IJobList $jobList;

    /**
     * Webhook log mapper
     *
     * @var WebhookLogMapper
     */
    private WebhookLogMapper $webhookLogMapper;


    /**
     * Constructor
     *
     * @param WebhookMapper    $webhookMapper  Webhook mapper
     * @param WebhookLogMapper $webhookLogMapper Webhook log mapper
     * @param Client           $client         HTTP client
     * @param LoggerInterface  $logger         Logger
     * @param IJobList         $jobList        Background job list
     */
    public function __construct(
        WebhookMapper $webhookMapper,
        WebhookLogMapper $webhookLogMapper,
        Client $client,
        LoggerInterface $logger,
        IJobList $jobList
    ) {
        $this->webhookMapper   = $webhookMapper;
        $this->webhookLogMapper = $webhookLogMapper;
        $this->client          = $client;
        $this->logger          = $logger;
        $this->jobList         = $jobList;

    }//end __construct()


    /**
     * Dispatch event to all matching webhooks
     *
     * @param Event  $event     The event to dispatch
     * @param string $eventName Event class name
     * @param array  $payload   Event payload data
     *
     * @return void
     */
    public function dispatchEvent(Event $event, string $eventName, array $payload): void
    {
        // Find all webhooks matching this event.
        $webhooks = $this->webhookMapper->findForEvent($eventName);

        if (empty($webhooks) === true) {
            $this->logger->debug(
                    'No webhooks configured for event',
                    [
                        'event' => $eventName,
                    ]
                    );
            return;
        }

        $this->logger->info(
                message: 'Dispatching event to webhooks',
                context: [
                    'event'         => $eventName,
                    'webhook_count' => count($webhooks),
                ]
                );

        foreach ($webhooks as $webhook) {
            $this->deliverWebhook(webhook: $webhook, eventName: $eventName, payload: $payload);
        }

    }//end dispatchEvent()


    /**
     * Deliver webhook to target URL
     *
     * @param Webhook $webhook   Webhook configuration
     * @param string  $eventName Event name
     * @param array   $payload   Payload data
     * @param int     $attempt   Current attempt number (for retries)
     *
     * @return bool Success status
     */
    public function deliverWebhook(Webhook $webhook, string $eventName, array $payload, int $attempt=1): bool
    {
        if ($webhook->getEnabled() === false) {
            $this->logger->debug(
                    message: 'Webhook is disabled, skipping delivery',
                    context: [
                        'webhook_id' => $webhook->getId(),
                        'event'      => $eventName,
                    ]
                    );
            return false;
        }

        // Apply filters if configured.
        if ($this->passesFilters(webhook: $webhook, payload: $payload) === false) {
            $this->logger->debug(
                    message: 'Webhook filters did not match, skipping delivery',
                    context: [
                        'webhook_id' => $webhook->getId(),
                        'event'      => $eventName,
                    ]
                    );
            return false;
        }

        $webhookPayload = $this->buildPayload(webhook: $webhook, eventName: $eventName, payload: $payload, attempt: $attempt);

        // Create webhook log entry.
        $webhookLog = new WebhookLog();
        $webhookLog->setWebhookId($webhook->getId());
        $webhookLog->setEventClass($eventName);
        $webhookLog->setPayloadArray($webhookPayload);
        $webhookLog->setUrl($webhook->getUrl());
        $webhookLog->setMethod($webhook->getMethod());
        $webhookLog->setAttempt($attempt);

        try {
            $response = $this->sendRequest(webhook: $webhook, payload: $webhookPayload);

            // Log success.
            $webhookLog->setSuccess(true);
            $webhookLog->setStatusCode($response['status_code']);
            $webhookLog->setResponseBody($response['body']);

            $this->logger->info(
                    message: 'Webhook delivered successfully',
                    context: [
                        'webhook_id'   => $webhook->getId(),
                        'webhook_name' => $webhook->getName(),
                        'event'        => $eventName,
                        'status_code'  => $response['status_code'],
                        'attempt'      => $attempt,
                    ]
                    );

            $this->webhookMapper->updateStatistics(webhook: $webhook, success: true);
            $this->webhookLogMapper->insert($webhookLog);

            return true;
        } catch (RequestException $e) {
            // Build detailed error message from Guzzle exception.
            $errorMessage = $e->getMessage();
            $errorDetails = [];

            // Get status code from exception if available.
            if ($e->hasResponse() === true) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $webhookLog->setStatusCode($statusCode);
                $errorDetails['status_code'] = $statusCode;

                try {
                    $responseBody = (string) $response->getBody();
                    $webhookLog->setResponseBody($responseBody);
                    $errorDetails['response_body'] = $responseBody;

                    // Try to parse JSON response for better error message.
                    $jsonResponse = json_decode($responseBody, true);
                    if ($jsonResponse !== null && (($jsonResponse['message'] ?? null) !== null)) {
                        $errorMessage .= ': '.$jsonResponse['message'];
                    } elseif ($jsonResponse !== null && (($jsonResponse['error'] ?? null) !== null)) {
                        $errorMessage .= ': '.$jsonResponse['error'];
                    }
                } catch (\Exception $bodyException) {
                    // Ignore body reading errors.
                }
            } else {
                // Connection error or timeout.
                $errorDetails['connection_error'] = true;
                if ($e->getCode() !== 0) {
                    $errorDetails['error_code'] = $e->getCode();
                }
            }

            // Add request details to error message.
            $errorDetails['request_url'] = $webhook->getUrl();
            $errorDetails['request_method'] = $webhook->getMethod();
            $errorDetails['timeout'] = $webhook->getTimeout();

            // Store request body as JSON for retry purposes (only on failure).
            $webhookLog->setRequestBody(json_encode($webhookPayload));

            // Log failure with detailed context.
            $webhookLog->setSuccess(false);
            $webhookLog->setErrorMessage($errorMessage);

            $this->logger->error(
                    message: 'Webhook delivery failed',
                    context: [
                        'webhook_id'   => $webhook->getId(),
                        'webhook_name' => $webhook->getName(),
                        'event'        => $eventName,
                        'error'        => $errorMessage,
                        'error_details' => $errorDetails,
                        'attempt'      => $attempt,
                        'max_retries'  => $webhook->getMaxRetries(),
                        'exception_class' => get_class($e),
                        'exception_code' => $e->getCode(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            $this->webhookMapper->updateStatistics(webhook: $webhook, success: false);

            // Schedule retry if within retry limit.
            if ($attempt < $webhook->getMaxRetries()) {
                $nextRetryAt = $this->calculateNextRetryTime(webhook: $webhook, attempt: $attempt);
                $webhookLog->setNextRetryAt($nextRetryAt);
                $this->scheduleRetry(webhook: $webhook, eventName: $eventName, payload: $payload, attempt: $attempt + 1);
            }

            // Save log entry.
            $this->webhookLogMapper->insert($webhookLog);

            return false;
        } catch (\Exception $e) {
            // Log unexpected errors.
            $webhookLog->setSuccess(false);
            $webhookLog->setErrorMessage($e->getMessage());
            $this->webhookLogMapper->insert($webhookLog);

            $this->logger->error(
                    message: 'Unexpected error during webhook delivery',
                    context: [
                        'webhook_id' => $webhook->getId(),
                        'event'      => $eventName,
                        'error'      => $e->getMessage(),
                    ]
                    );

            return false;
        }//end try

    }//end deliverWebhook()


    /**
     * Check if payload passes webhook filters
     *
     * @param Webhook $webhook Webhook configuration
     * @param array   $payload Event payload
     *
     * @return bool
     */
    private function passesFilters(Webhook $webhook, array $payload): bool
    {
        $filters = $webhook->getFiltersArray();

        if (empty($filters) === true) {
            return true;
        }

        foreach ($filters as $key => $value) {
            // Support dot notation for nested keys.
            $actualValue = $this->getNestedValue(array: $payload, key: $key);

            // If filter value is array, check if actual value is in array.
            if (is_array($value) === true) {
                if (in_array($actualValue, $value) === false) {
                    return false;
                }
            } else if ($actualValue !== $value) {
                return false;
            }
        }

        return true;

    }//end passesFilters()


    /**
     * Get nested value from array using dot notation
     *
     * @param array  $array Array to search
     * @param string $key   Dot-notated key
     *
     * @return mixed
     */
    private function getNestedValue(array $array, string $key)
    {
        $keys = explode('.', $key);

        foreach ($keys as $k) {
            if (!isset($array[$k])) {
                return null;
            }

            $array = $array[$k];
        }

        return $array;

    }//end getNestedValue()


    /**
     * Build webhook payload
     *
     * @param Webhook $webhook   Webhook configuration
     * @param string  $eventName Event name
     * @param array   $payload   Event payload
     * @param int     $attempt   Delivery attempt number
     *
     * @return array
     */
    private function buildPayload(Webhook $webhook, string $eventName, array $payload, int $attempt): array
    {
        return [
            'event'     => $eventName,
            'webhook'   => [
                'id'   => $webhook->getUuid(),
                'name' => $webhook->getName(),
            ],
            'data'      => $payload,
            'timestamp' => date('c'),
            'attempt'   => $attempt,
        ];

    }//end buildPayload()


    /**
     * Send HTTP request to webhook URL
     *
     * @param Webhook $webhook Webhook configuration
     * @param array   $payload Payload to send
     *
     * @return array Response data
     * @throws RequestException
     */
    private function sendRequest(Webhook $webhook, array $payload): array
    {
        $headers = array_merge(
            [
                'Content-Type' => 'application/json',
                'User-Agent'   => 'OpenRegister-Webhooks/1.0',
            ],
            $webhook->getHeadersArray()
        );

        // Add signature if secret is configured.
        if ($webhook->getSecret() !== null) {
            $signature = $this->generateSignature(payload: $payload, secret: $webhook->getSecret());
            $headers['X-Webhook-Signature'] = $signature;
        }

        $options = [
            'headers' => $headers,
            'timeout' => $webhook->getTimeout(),
        ];

        // For GET requests, use query parameters instead of JSON body.
        if (strtoupper($webhook->getMethod()) === 'GET') {
            $options['query'] = $payload;
        } else {
            // For POST, PUT, PATCH, DELETE, send JSON body.
            $options['json'] = $payload;
        }

        $response = $this->client->request(
            method: $webhook->getMethod(),
            uri: $webhook->getUrl(),
            options: $options
        );

        return [
            'status_code' => $response->getStatusCode(),
            'body'        => (string) $response->getBody(),
        ];

    }//end sendRequest()


    /**
     * Generate HMAC signature for payload
     *
     * @param array  $payload Payload to sign
     * @param string $secret  Secret key
     *
     * @return string
     */
    private function generateSignature(array $payload, string $secret): string
    {
        return hash_hmac('sha256', json_encode($payload), $secret);

    }//end generateSignature()


    /**
     * Schedule retry for failed webhook delivery
     *
     * The retry is handled by the WebhookRetryJob cron job which runs every 5 minutes
     * and checks for failed webhook logs with next_retry_at timestamps that have passed.
     * The retry delay is stored in the webhook log's next_retry_at field using
     * exponential backoff based on the webhook's retry policy.
     *
     * @param Webhook $webhook   Webhook configuration
     * @param string  $eventName Event name
     * @param array   $payload   Payload data
     * @param int     $attempt   Next attempt number
     *
     * @return void
     */
    private function scheduleRetry(Webhook $webhook, string $eventName, array $payload, int $attempt): void
    {
        $delay = $this->calculateRetryDelay(webhook: $webhook, attempt: $attempt);

        $this->logger->info(
                message: 'Scheduling webhook retry',
                context: [
                    'webhook_id'   => $webhook->getId(),
                    'webhook_name' => $webhook->getName(),
                    'event'        => $eventName,
                    'attempt'      => $attempt,
                    'delay'        => $delay,
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
     * @param int     $attempt Current attempt number
     *
     * @return DateTime Next retry timestamp
     */
    private function calculateNextRetryTime(Webhook $webhook, int $attempt): DateTime
    {
        $delay = $this->calculateRetryDelay(webhook: $webhook, attempt: $attempt);
        $nextRetry = new DateTime();
        $nextRetry->modify('+'.$delay.' seconds');

        return $nextRetry;

    }//end calculateNextRetryTime()


    /**
     * Calculate retry delay based on retry policy
     *
     * @param Webhook $webhook Webhook configuration
     * @param int     $attempt Attempt number
     *
     * @return int Delay in seconds
     */
    private function calculateRetryDelay(Webhook $webhook, int $attempt): int
    {
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


}//end class
