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
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use DateTime;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\Webhook\CloudEventFormatter;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * WebhookService handles webhook delivery and request interception
 *
 * This service provides two main capabilities:
 * 1. Post-event webhook delivery - Sends webhooks after events occur
 * 2. Pre-request webhook interception - Intercepts requests before controller execution
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex webhook delivery with retry and interception logic
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
     * @var GuzzleClient
     */
    private GuzzleClient $client;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Webhook log mapper
     *
     * @var WebhookLogMapper
     */
    private WebhookLogMapper $webhookLogMapper;

    /**
     * CloudEvent formatter
     *
     * @var CloudEventFormatter|null
     */
    private ?CloudEventFormatter $cloudEventFormatter;

    /**
     * Constructor
     *
     * @param WebhookMapper            $webhookMapper       Webhook mapper
     * @param LoggerInterface          $logger              Logger
     * @param WebhookLogMapper         $webhookLogMapper    Webhook log mapper
     * @param CloudEventFormatter|null $cloudEventFormatter CloudEvent formatter (optional)
     *
     * @return void
     */
    public function __construct(
        WebhookMapper $webhookMapper,
        LoggerInterface $logger,
        WebhookLogMapper $webhookLogMapper,
        ?CloudEventFormatter $cloudEventFormatter=null
    ) {
        $this->webhookMapper    = $webhookMapper;
        $this->logger           = $logger;
        $this->webhookLogMapper = $webhookLogMapper;
        $this->cloudEventFormatter = $cloudEventFormatter;
        $this->initializeHttpClient();
    }//end __construct()

    /**
     * Initialize HTTP client with default configuration
     *
     * Creates a GuzzleHttp\Client instance with appropriate defaults for webhook delivery.
     * Allows self-signed certificates and configures timeouts appropriately.
     *
     * @return void
     */
    private function initializeHttpClient(): void
    {
        // Prepare Guzzle client configuration.
        // Allow self-signed certificates for webhook endpoints.
        // Don't throw exceptions for 4xx/5xx responses (we handle them manually).
        $clientConfig = [
            'timeout'         => 30,
            'connect_timeout' => 10,
            'verify'          => false,
            'allow_redirects' => true,
            'http_errors'     => false,
        ];

        $this->client = new GuzzleClient($clientConfig);
    }//end initializeHttpClient()

    /**
     * Dispatch event to all matching webhooks
     *
     * @param Event  $_event    The event to dispatch (unused but provided by event system)
     * @param string $eventName Event class name
     * @param array  $payload   Event payload data
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple webhook dispatch conditions
     */
    public function dispatchEvent(Event $_event, string $eventName, array $payload): void
    {
        try {
            // Find all webhooks matching this event.
            $webhooks = $this->webhookMapper->findForEvent($eventName);
        } catch (\Exception $e) {
            // If table doesn't exist yet (migrations haven't run), silently skip webhook delivery.
            $this->logger->debug(
                'Webhook table does not exist yet, skipping webhook delivery',
                [
                    'event' => $eventName,
                    'error' => $e->getMessage(),
                ]
            );
            return;
        }

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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex delivery with retry and error handling
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple exception handling paths
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive webhook delivery with logging
     * @SuppressWarnings(PHPMD.ElseExpression)        Fallback for connection errors without response
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

        $webhookPayload = $this->buildPayload(
            webhook: $webhook,
            eventName: $eventName,
            payload: $payload,
            attempt: $attempt
        );

        // Create webhook log entry.
        $webhookLog = new WebhookLog();
        $webhookLog->setWebhook($webhook->getId());
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
                $response   = $e->getResponse();
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
                    } else if ($jsonResponse !== null && (($jsonResponse['error'] ?? null) !== null)) {
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
            }//end if

            // Add request details to error message.
            $errorDetails['request_url']    = $webhook->getUrl();
            $errorDetails['request_method'] = $webhook->getMethod();
            $errorDetails['timeout']        = $webhook->getTimeout();

            // Store request body as JSON for retry purposes (only on failure).
            $webhookLog->setRequestBody(json_encode($webhookPayload));

            // Log failure with detailed context.
            $webhookLog->setSuccess(false);
            $webhookLog->setErrorMessage($errorMessage);

            $this->logger->error(
                message: 'Webhook delivery failed',
                context: [
                    'webhook_id'      => $webhook->getId(),
                    'webhook_name'    => $webhook->getName(),
                    'event'           => $eventName,
                    'error'           => $errorMessage,
                    'error_details'   => $errorDetails,
                    'attempt'         => $attempt,
                    'max_retries'     => $webhook->getMaxRetries(),
                    'exception_class' => get_class($e),
                    'exception_code'  => $e->getCode(),
                    'trace'           => $e->getTraceAsString(),
                ]
            );

            $this->webhookMapper->updateStatistics(webhook: $webhook, success: false);

            // Schedule retry if within retry limit.
            if ($attempt < $webhook->getMaxRetries()) {
                $nextRetryAt = $this->calculateNextRetryTime(webhook: $webhook, attempt: $attempt);
                $webhookLog->setNextRetryAt($nextRetryAt);
                $this->scheduleRetry(webhook: $webhook, eventName: $eventName, _payload: $payload, attempt: $attempt + 1);
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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple filter condition checks
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
            if (isset($array[$k]) === false) {
                return null;
            }

            $array = $array[$k];
        }

        return $array;
    }//end getNestedValue()

    /**
     * Build webhook payload
     *
     * Builds the webhook payload in either standard format or CloudEvents format
     * based on webhook configuration.
     *
     * @param Webhook $webhook   Webhook configuration
     * @param string  $eventName Event name
     * @param array   $payload   Event payload
     * @param int     $attempt   Delivery attempt number
     *
     * @return array Webhook payload in standard or CloudEvents format.
     */
    private function buildPayload(Webhook $webhook, string $eventName, array $payload, int $attempt): array
    {
        // Check if webhook is configured to use CloudEvents format.
        $config         = $webhook->getConfigurationArray();
        $useCloudEvents = ($config['useCloudEvents'] ?? false) === true;

        // Use CloudEvents format if configured and formatter is available.
        if ($useCloudEvents === true && $this->cloudEventFormatter !== null) {
            // Add webhook metadata to payload.
            $enrichedPayload = array_merge(
                $payload,
                [
                    'webhook' => [
                        'id'   => $webhook->getUuid(),
                        'name' => $webhook->getName(),
                    ],
                    'attempt' => $attempt,
                ]
            );

            return $this->cloudEventFormatter->formatAsCloudEvent(
                eventType: $eventName,
                payload: $enrichedPayload,
                source: $config['cloudEventSource'] ?? null,
                subject: $config['cloudEventSubject'] ?? null
            );
        }

        // Use standard format.
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
     * @return (int|string)[] Response data
     *
     * @throws RequestException
     *
     * @psalm-return array{status_code: int, body: string}
     *
     * @SuppressWarnings(PHPMD.ElseExpression) Different handling for GET vs POST/PUT/PATCH/DELETE methods
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
     * @param array   $_payload  Payload data (unused but required for retry tracking)
     * @param int     $attempt   Next attempt number
     *
     * @return void
     */
    private function scheduleRetry(Webhook $webhook, string $eventName, array $_payload, int $attempt): void
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
        $delay     = $this->calculateRetryDelay(webhook: $webhook, attempt: $attempt);
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

    /**
     * Intercept request and send to webhooks
     *
     * Finds webhooks configured for "before" events matching this request,
     * sends CloudEvent-formatted payloads, and optionally processes responses
     * to modify the request.
     *
     * This enables pre-request webhook notifications and request modification
     * based on webhook responses.
     *
     * @param IRequest $request   The HTTP request
     * @param string   $eventType The event type (e.g., 'object.creating')
     *
     * @return array Modified request data or original request data
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex request interception logic
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple webhook processing paths
     * @SuppressWarnings(PHPMD.ElseExpression)       Fallback when formatter is unavailable
     */
    public function interceptRequest(IRequest $request, string $eventType): array
    {
        // Find webhooks configured for this event type.
        $webhooks = $this->findWebhooksForInterception($eventType);

        if (empty($webhooks) === true) {
            // No webhooks configured, return original request data.
            return $request->getParams();
        }

        // Format request as CloudEvent if formatter is available.
        if ($this->cloudEventFormatter !== null) {
            $cloudEvent = $this->cloudEventFormatter->formatRequestAsCloudEvent(
                request: $request,
                eventType: $eventType
            );
        } else {
            // Fallback to basic request data.
            $cloudEvent = [
                'type'   => $eventType,
                'method' => $request->getMethod(),
                'path'   => $request->getPathInfo(),
                'body'   => $request->getParams(),
            ];
        }

        // Get original request data.
        $requestData  = $request->getParams();
        $modifiedData = $requestData;

        // Process each webhook.
        foreach ($webhooks as $webhook) {
            try {
                // Convert CloudEvent to standard webhook payload format.
                $webhookPayload = [
                    'objectType' => 'request',
                    'action'     => $eventType,
                    'cloudEvent' => $cloudEvent,
                ];

                // Deliver webhook.
                $success = $this->deliverWebhook(
                    webhook: $webhook,
                    eventName: $eventType,
                    payload: $webhookPayload,
                    attempt: 1
                );

                // Process response if webhook is configured to handle responses.
                // Note: This is currently limited as we're using fire-and-forget delivery.
                // TODO: Implement response handling if needed.
                if ($success === true && $this->shouldProcessResponse($webhook) === true) {
                    $this->logger->info(
                        'Webhook delivery successful but response processing not yet implemented',
                        [
                            'webhook_id' => $webhook->getId(),
                            'event_type' => $eventType,
                        ]
                    );
                }
            } catch (\Exception $e) {
                // Log failure but continue processing other webhooks.
                $this->logger->error(
                    'Failed to deliver webhook during request interception',
                    [
                        'webhook_id'   => $webhook->getId(),
                        'webhook_name' => $webhook->getName(),
                        'event_type'   => $eventType,
                        'error'        => $e->getMessage(),
                    ]
                );

                // Continue processing other webhooks even if one fails.
                continue;
            }//end try
        }//end foreach

        return $modifiedData;
    }//end interceptRequest()

    /**
     * Find webhooks configured for request interception
     *
     * Finds webhooks that are configured for request interception and match
     * the specified event type.
     *
     * @param string $eventType Event type (e.g., 'object.creating')
     *
     * @return array List of matching webhooks
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Webhook>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple webhook filtering conditions
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple filter matching paths
     */
    private function findWebhooksForInterception(string $eventType): array
    {
        // Get all enabled webhooks.
        $allWebhooks = $this->webhookMapper->findEnabled();

        // Filter webhooks that match this event type and are configured for interception.
        $matchingWebhooks = [];

        foreach ($allWebhooks as $webhook) {
            $config = $webhook->getConfigurationArray();

            // Check if webhook is configured for request interception.
            if (($config['interceptRequests'] ?? false) !== true) {
                continue;
            }

            // Check if webhook listens to this event type.
            $events = $webhook->getEventsArray();
            if (empty($events) === false) {
                // Check if event type matches.
                $eventClass = $this->eventTypeToEventClass($eventType);
                if ($webhook->matchesEvent($eventClass) === false) {
                    continue;
                }
            }

            $matchingWebhooks[] = $webhook;
        }//end foreach

        return $matchingWebhooks;
    }//end findWebhooksForInterception()

    /**
     * Check if webhook response should be processed
     *
     * Determines if the webhook is configured to have its response processed
     * to modify the incoming request.
     *
     * @param Webhook $webhook Webhook configuration
     *
     * @return bool True if response should be processed
     */
    private function shouldProcessResponse(Webhook $webhook): bool
    {
        $config = $webhook->getConfigurationArray();

        // Process response if configured to do so and not async.
        return ($config['processResponse'] ?? false) === true
            && ($config['async'] ?? false) === false;
    }//end shouldProcessResponse()

    /**
     * Convert event type to event class name
     *
     * Converts a dot-notation event type to the corresponding event class name.
     *
     * @param string $eventType Event type (e.g., 'object.creating')
     *
     * @return string Event class name (e.g., 'OCA\OpenRegister\Event\ObjectCreatingEvent')
     */
    private function eventTypeToEventClass(string $eventType): string
    {
        // Convert event type to event class name.
        // Example: 'object.creating' -> 'OCA\OpenRegister\Event\ObjectCreatingEvent'.
        $parts  = explode('.', $eventType);
        $entity = ucfirst($parts[0]);
        $action = ucfirst($parts[1] ?? 'created');

        return 'OCA\\OpenRegister\\Event\\'.$entity.$action.'Event';
    }//end eventTypeToEventClass()
}//end class
