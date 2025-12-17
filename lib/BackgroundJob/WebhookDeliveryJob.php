<?php
/**
 * OpenRegister Webhook Delivery Job
 *
 * Background job for webhook delivery with retries.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\WebhookMapper;
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
class WebhookDeliveryJob extends QueuedJob
{

    /**
     * Webhook mapper
     *
     * Handles database operations for webhook entities.
     *
     * @var WebhookMapper Webhook mapper instance
     */
    private readonly WebhookMapper $webhookMapper;

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
     * @param ITimeFactory    $time           Time factory for job scheduling
     * @param WebhookMapper   $webhookMapper  Webhook mapper for database operations
     * @param WebhookService  $webhookService Webhook service for delivery logic
     * @param LoggerInterface $logger         Logger for error tracking
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        WebhookMapper $webhookMapper,
        WebhookService $webhookService,
        LoggerInterface $logger
    ) {
        // Call parent constructor to initialize base job with time factory.
        parent::__construct($time);

        // Store dependencies for use in job execution.
        $this->webhookMapper  = $webhookMapper;
        $this->webhookService = $webhookService;
        $this->logger         = $logger;

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
     */
    protected function run($argument): void
    {
        // Extract job arguments with defaults.
        $webhookId = $argument['webhook_id'] ?? null;
        $eventName = $argument['event_name'] ?? null;
        $payload   = $argument['payload'] ?? [];
        $attempt   = $argument['attempt'] ?? 1;

        if ($webhookId === null || $eventName === null) {
            $this->logger->error(
                    'WebhookDeliveryJob called with invalid arguments',
                    [
                        'argument' => $argument,
                    ]
                    );
            return;
        }

        try {
            $webhook = $this->webhookMapper->find($webhookId);

            $this->logger->info(
                'Executing webhook delivery job',
                [
                    'webhook_id'   => $webhookId,
                    'webhook_name' => $webhook->getName(),
                    'event'        => $eventName,
                    'attempt'      => $attempt,
                ]
            );

            // Deliver webhook.
            $success = $this->webhookService->deliverWebhook(
                webhook: $webhook,
                eventName: $eventName,
                payload: $payload,
                attempt: $attempt
            );

            if ($success === true) {
                $this->logger->info(
                    'Webhook delivery job completed successfully',
                    [
                        'webhook_id'   => $webhookId,
                        'webhook_name' => $webhook->getName(),
                        'event'        => $eventName,
                        'attempt'      => $attempt,
                    ]
                );
            }//end if

            if ($success === false) {
                $this->logger->warning(
                    'Webhook delivery job failed',
                    [
                        'webhook_id'   => $webhookId,
                        'webhook_name' => $webhook->getName(),
                        'event'        => $eventName,
                        'attempt'      => $attempt,
                    ]
                );
            }//end if
        } catch (\Exception $e) {
            $this->logger->error(
                'Webhook delivery job encountered an exception',
                [
                    'webhook_id' => $webhookId,
                    'event'      => $eventName,
                    'attempt'    => $attempt,
                    'error'      => $e->getMessage(),
                    'trace'      => $e->getTraceAsString(),
                ]
            );
        }//end try

    }//end run()


}//end class
