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
 * This job handles asynchronous webhook delivery, particularly for retries
 * after failed delivery attempts.
 */
class WebhookDeliveryJob extends QueuedJob
{

    /**
     * Webhook mapper
     *
     * @var WebhookMapper
     */
    private WebhookMapper $webhookMapper;

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
     * @param ITimeFactory    $time           Time factory
     * @param WebhookMapper   $webhookMapper  Webhook mapper
     * @param WebhookService  $webhookService Webhook service
     * @param LoggerInterface $logger         Logger
     */
    public function __construct(
        ITimeFactory $time,
        WebhookMapper $webhookMapper,
        WebhookService $webhookService,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->webhookMapper  = $webhookMapper;
        $this->webhookService = $webhookService;
        $this->logger         = $logger;

    }//end __construct()


    /**
     * Run the background job
     *
     * @param array $argument Job arguments containing:
     *                        - webhook_id: Webhook ID
     *                        - event_name: Event class name
     *                        - payload: Event payload data
     *                        - attempt: Current attempt number
     *
     * @return void
     */
    protected function run($argument): void
    {
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
                $webhook,
                $eventName,
                $payload,
                $attempt
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
            } else {
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
