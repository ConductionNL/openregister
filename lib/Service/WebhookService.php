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
use OCA\OpenRegister\Db\Webhook;
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
     * Constructor
     *
     * @param WebhookMapper   $webhookMapper Webhook mapper
     * @param Client          $client        HTTP client
     * @param LoggerInterface $logger        Logger
     * @param IJobList        $jobList       Background job list
     */
    public function __construct(
        WebhookMapper $webhookMapper,
        Client $client,
        LoggerInterface $logger,
        IJobList $jobList
    ) {
        $this->webhookMapper = $webhookMapper;
        $this->client        = $client;
        $this->logger        = $logger;
        $this->jobList       = $jobList;

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

        try {
            $response = $this->sendRequest(webhook: $webhook, payload: $webhookPayload);

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

            return true;
        } catch (RequestException $e) {
            $this->logger->error(
                    message: 'Webhook delivery failed',
                    context: [
                        'webhook_id'   => $webhook->getId(),
                        'webhook_name' => $webhook->getName(),
                        'event'        => $eventName,
                        'error'        => $e->getMessage(),
                        'attempt'      => $attempt,
                        'max_retries'  => $webhook->getMaxRetries(),
                    ]
                    );

            $this->webhookMapper->updateStatistics(webhook: $webhook, success: false);

            // Schedule retry if within retry limit.
            if ($attempt < $webhook->getMaxRetries()) {
                $this->scheduleRetry(webhook: $webhook, eventName: $eventName, payload: $payload, attempt: $attempt + 1);
            }

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
            'json'    => $payload,
            'timeout' => $webhook->getTimeout(),
        ];

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

        // Use background job for retry.
        $this->jobList->add(
                'OCA\OpenRegister\BackgroundJob\WebhookDeliveryJob',
                [
                    'webhook_id' => $webhook->getId(),
                    'event_name' => $eventName,
                    'payload'    => $payload,
                    'attempt'    => $attempt,
                ]
                );

    }//end scheduleRetry()


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
