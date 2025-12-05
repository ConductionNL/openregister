<?php
/**
 * OpenRegister Webhook Interceptor Service
 *
 * Service for intercepting requests before they reach controllers and sending
 * them to configured webhooks as CloudEvents. Can process responses to modify
 * the request before it continues to the controller.
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

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Webhook Interceptor Service
 *
 * Intercepts HTTP requests before they reach controllers, sends them to
 * configured webhooks as CloudEvents, and optionally processes responses
 * to modify the request.
 *
 * @category Service
 * @package  OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/openregister
 */
class WebhookInterceptorService
{

    /**
     * CloudEvent service
     *
     * @var CloudEventService
     */
    private CloudEventService $cloudEventService;

    /**
     * Webhook mapper
     *
     * @var WebhookMapper
     */
    private WebhookMapper $webhookMapper;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * HTTP client
     *
     * @var Client
     */
    private Client $httpClient;

    /**
     * Webhook log mapper
     *
     * @var WebhookLogMapper
     */
    private WebhookLogMapper $webhookLogMapper;


    /**
     * Intercept request and send to webhooks
     *
     * Finds webhooks configured for "before" events matching this request,
     * sends CloudEvent-formatted payloads, and optionally processes responses
     * to modify the request.
     *
     * @param IRequest $request   The HTTP request
     * @param string   $eventType The event type (e.g., 'object.creating')
     *
     * @return array Modified request data or original request data
     */
    public function interceptRequest(IRequest $request, string $eventType): array
    {
        // Find webhooks configured for this event type.
        $webhooks = $this->findWebhooksForEvent($eventType);

        if (empty($webhooks) === true) {
            // No webhooks configured, return original request data.
            return $request->getParams();
        }

        // Format request as CloudEvent.
        $cloudEvent = $this->cloudEventService->formatRequestAsCloudEvent(
            request: $request,
            eventType: $eventType
        );

        // Get original request data.
        $requestData  = $request->getParams();
        $modifiedData = $requestData;

        // Process each webhook.
        foreach ($webhooks as $webhook) {
            // Create webhook log entry.
            $webhookLog = new WebhookLog();
            $webhookLog->setWebhookId($webhook->getId());
            $webhookLog->setEventClass($eventType);
            $webhookLog->setPayloadArray($cloudEvent);
            $webhookLog->setUrl($webhook->getUrl());
            $webhookLog->setMethod($webhook->getMethod());
            $webhookLog->setAttempt(1);

            try {
                $response = $this->sendCloudEventToWebhook(
                    webhook: $webhook,
                    cloudEvent: $cloudEvent
                );

                // Log success.
                $webhookLog->setSuccess(true);
                if ($response !== null && (($response['statusCode'] ?? null) !== null)) {
                    $webhookLog->setStatusCode($response['statusCode']);
                    $webhookLog->setResponseBody(json_encode($response));
                }

                $this->webhookLogMapper->insert($webhookLog);

                // Process response if webhook is configured to handle responses.
                if ($response !== null && $this->shouldProcessResponse($webhook) === true) {
                    $modifiedData = $this->processWebhookResponse(
                        webhook: $webhook,
                        response: $response,
                        originalData: $modifiedData
                    );
                }
            } catch (\Exception $e) {
                // Log failure.
                $webhookLog->setSuccess(false);
                $webhookLog->setErrorMessage($e->getMessage());

                // Get status code from exception if available.
                // GuzzleException methods exist but Psalm may not recognize them without proper type check.
                if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse() === true) {
                    $response = $e->getResponse();
                    if ($response !== null) {
                        $webhookLog->setStatusCode($response->getStatusCode());
                        try {
                            $webhookLog->setResponseBody((string) $response->getBody());
                        } catch (\Exception $bodyException) {
                            // Ignore body reading errors.
                        }
                    }
                }

                $this->logger->error(
                    'Failed to send CloudEvent to webhook',
                    [
                        'webhook_id'   => $webhook->getId(),
                        'webhook_name' => $webhook->getName(),
                        'event_type'   => $eventType,
                        'error'        => $e->getMessage(),
                    ]
                );

                // Schedule retry if configured.
                $config = $webhook->getConfigurationArray();
                if (($config['enableRetries'] ?? true) === true && $webhook->getMaxRetries() > 1) {
                    $nextRetryAt = $this->calculateNextRetryTime(webhook: $webhook, attempt: 1);
                    $webhookLog->setNextRetryAt($nextRetryAt);
                }

                // Save log entry.
                $this->webhookLogMapper->insert($webhookLog);

                // Continue processing other webhooks even if one fails.
                continue;
            }//end try
        }//end foreach

        return $modifiedData;

    }//end interceptRequest()


    /**
     * Find webhooks configured for event
     *
     * @param string $eventType Event type (e.g., 'object.creating')
     *
     * @return Webhook[] Array of matching webhooks
     */
    private function findWebhooksForEvent(string $eventType): array
    {
        // Get all enabled webhooks.
        $allWebhooks = $this->webhookMapper->findEnabled();

        // Filter webhooks that match this event type and are configured for "before" events.
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

    }//end findWebhooksForEvent()


    /**
     * Send CloudEvent to webhook
     *
     * @param Webhook $webhook    Webhook configuration
     * @param array   $cloudEvent CloudEvent payload
     *
     * @return array|null Webhook response data or null if async
     */
    private function sendCloudEventToWebhook(Webhook $webhook, array $cloudEvent): ?array
    {
        $config = $webhook->getConfigurationArray();

        // Check if webhook is async (fire and forget).
        $isAsync = ($config['async'] ?? false) === true;

        try {
            // Prepare headers.
            $headers = [
                'Content-Type' => 'application/cloudevents+json',
            ];

            // Add custom headers from webhook configuration.
            $customHeaders = $webhook->getHeadersArray();
            foreach ($customHeaders as $key => $value) {
                $headers[$key] = $value;
            }

            // Add HMAC signature if secret is configured.
            if ($webhook->getSecret() !== null) {
                $payload   = json_encode($cloudEvent);
                $signature = hash_hmac('sha256', $payload, $webhook->getSecret());
                $headers['X-Webhook-Signature'] = $signature;
            }

            // Send CloudEvent.
            $response = $this->httpClient->request(
                method: $webhook->getMethod(),
                uri: $webhook->getUrl(),
                options: [
                    'json'        => $cloudEvent,
                    'headers'     => $headers,
                    'timeout'     => $webhook->getTimeout(),
                    'http_errors' => false,
                ]
            );

            // Update webhook statistics.
            $this->updateWebhookStatistics($webhook, $response->getStatusCode() < 400);

            // Return response if not async.
            if ($isAsync === false) {
                $responseBody = $response->getBody()->getContents();
                $decoded      = json_decode($responseBody, true);

                return $decoded ?? ['body' => $responseBody];
            }

            return null;
        } catch (GuzzleException $e) {
            // Update webhook statistics.
            $this->updateWebhookStatistics($webhook, false);

            throw $e;
        }//end try

    }//end sendCloudEventToWebhook()


    /**
     * Check if webhook response should be processed
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
     * Process webhook response to modify request
     *
     * @param Webhook $webhook      Webhook configuration
     * @param array   $response     Webhook response data
     * @param array   $originalData Original request data
     *
     * @return array Modified request data
     */
    private function processWebhookResponse(
        Webhook $webhook,
        array $response,
        array $originalData
    ): array {
        $config = $webhook->getConfigurationArray();

        // Get response processing configuration.
        $responseConfig = $config['responseProcessing'] ?? [];

        // Determine how to merge response into request data.
        $mergeStrategy = $responseConfig['mergeStrategy'] ?? 'merge';

        switch ($mergeStrategy) {
            case 'replace':
                // Replace entire request body with response data.
                return $response['data'] ?? $response;

            case 'merge':
                // Merge response data into request data (default).
                return array_merge($originalData, $response['data'] ?? $response);

            case 'custom':
                // Use custom field mapping.
                return $this->applyCustomMapping(
                    originalData: $originalData,
                    response: $response,
                    mapping: $responseConfig['fieldMapping'] ?? []
                );

            default:
                // Unknown strategy, return original data.
                $this->logger->warning(
                    'Unknown response merge strategy',
                    [
                        'webhook_id' => $webhook->getId(),
                        'strategy'   => $mergeStrategy,
                    ]
                );

                return $originalData;
        }//end switch

    }//end processWebhookResponse()


    /**
     * Apply custom field mapping
     *
     * @param array $originalData Original request data
     * @param array $response     Webhook response data
     * @param array $mapping      Field mapping configuration
     *
     * @return array Modified request data
     */
    private function applyCustomMapping(
        array $originalData,
        array $response,
        array $mapping
    ): array {
        $modifiedData = $originalData;

        foreach ($mapping as $responseField => $requestField) {
            if (($response[$responseField] ?? null) !== null) {
                // Support nested field access using dot notation.
                $this->setNestedValue($modifiedData, $requestField, $response[$responseField]);
            }
        }

        return $modifiedData;

    }//end applyCustomMapping()


    /**
     * Set nested value using dot notation
     *
     * @param array  $data  Data array
     * @param string $path  Dot-notation path (e.g., 'user.name')
     * @param mixed  $value Value to set
     *
     * @return         void
     * @psalm-suppress UnusedParam - Parameter kept for interface compatibility
     */
    private function setNestedValue(array &$data, string $path, $value): void
    {
        $keys    = explode('.', $path);
        $current = &$data;

        foreach ($keys as $key) {
            /*
             * @psalm-suppress TypeDoesNotContainType
             */
            if (!isset($current[$key]) === false || is_array($current[$key]) === false) {
                $current[$key] = [];
            }

            $current = &$current[$key];
        }

        // Set the value at the final nested path.
        /*
         * @psalm-suppress UnusedVariable
         */
        $current = $value;

    }//end setNestedValue()


    /**
     * Update webhook statistics
     *
     * @param Webhook $webhook Webhook entity
     * @param bool    $success Whether delivery was successful
     *
     * @return void
     */
    private function updateWebhookStatistics(Webhook $webhook, bool $success): void
    {
        try {
            $this->webhookMapper->updateStatistics($webhook, $success);
        } catch (\Exception $e) {
            // Log error but don't fail the request.
            $this->logger->error(
                'Failed to update webhook statistics',
                [
                    'webhook_id' => $webhook->getId(),
                    'error'      => $e->getMessage(),
                ]
            );
        }

    }//end updateWebhookStatistics()


    /**
     * Convert event type to event class name
     *
     * @param string $eventType Event type (e.g., 'object.creating')
     *
     * @return string Event class name
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


}//end class
