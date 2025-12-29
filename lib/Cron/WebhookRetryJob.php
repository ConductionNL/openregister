<?php

/**
 * OpenRegister Webhook Retry Job
 *
 * Cron job that processes failed webhook deliveries and retries them
 * based on their next_retry_at timestamp. Uses exponential backoff
 * with increasing intervals between retries.
 *
 * @category Cron
 * @package  OCA\OpenRegister\Cron
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

namespace OCA\OpenRegister\Cron;

use DateTime;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
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
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 *
 * @psalm-suppress UnusedClass
 */
class WebhookRetryJob extends TimedJob
{
    /**
     * Default interval: 5 minutes
     */
    private const DEFAULT_INTERVAL = 300;

    /**
     * Webhook mapper
     *
     * @var WebhookMapper
     */
    private WebhookMapper $webhookMapper;

    /**
     * Webhook log mapper
     *
     * @var WebhookLogMapper
     */
    private WebhookLogMapper $webhookLogMapper;

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
     * @param ITimeFactory     $time             Time factory
     * @param WebhookMapper    $webhookMapper    Webhook mapper
     * @param WebhookLogMapper $webhookLogMapper Webhook log mapper
     * @param WebhookService   $webhookService   Webhook service
     * @param LoggerInterface  $logger           Logger
     */
    public function __construct(
        ITimeFactory $time,
        WebhookMapper $webhookMapper,
        WebhookLogMapper $webhookLogMapper,
        WebhookService $webhookService,
        LoggerInterface $logger
    ) {
        parent::__construct($time);

        $this->webhookMapper    = $webhookMapper;
        $this->webhookLogMapper = $webhookLogMapper;
        $this->webhookService   = $webhookService;
        $this->logger           = $logger;

        // Set interval to 5 minutes.
        $this->setInterval(self::DEFAULT_INTERVAL);
    }//end __construct()

    /**
     * Run the retry job
     *
     * Finds failed webhook logs that are ready for retry and processes them.
     *
     * @param mixed $_argument Job arguments (unused)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($_argument): void
    {
        $now = new DateTime();

        $this->logger->debug(
            'Checking for webhook retries',
            [
                'timestamp' => $now->format('c'),
            ]
        );

        // Find failed logs ready for retry.
        $failedLogs = $this->webhookLogMapper->findFailedForRetry($now);

        if (empty($failedLogs) === true) {
            $this->logger->debug('No webhook retries needed');
            return;
        }

        $this->logger->info(
            'Processing webhook retries',
            [
                'count' => count($failedLogs),
            ]
        );

        foreach ($failedLogs as $log) {
            try {
                // Get webhook.
                $webhook = $this->webhookMapper->find($log->getWebhookId());

                // Check if webhook is still enabled.
                if ($webhook->getEnabled() === false) {
                    $this->logger->debug(
                        'Skipping retry for disabled webhook',
                        [
                            'webhook_id' => $webhook->getId(),
                            'log_id'     => $log->getId(),
                        ]
                    );
                    continue;
                }

                // Check if we've exceeded max retries.
                if ($log->getAttempt() >= $webhook->getMaxRetries()) {
                    $this->logger->warning(
                        'Webhook retry limit exceeded',
                        [
                            'webhook_id'  => $webhook->getId(),
                            'log_id'      => $log->getId(),
                            'attempt'     => $log->getAttempt(),
                            'max_retries' => $webhook->getMaxRetries(),
                        ]
                    );
                    continue;
                }

                // Retry webhook delivery.
                $this->logger->info(
                    'Retrying webhook delivery',
                    [
                        'webhook_id' => $webhook->getId(),
                        'log_id'     => $log->getId(),
                        'attempt'    => $log->getAttempt() + 1,
                    ]
                );

                $success = $this->webhookService->deliverWebhook(
                    webhook: $webhook,
                    eventName: $log->getEventClass(),
                    payload: $log->getPayloadArray(),
                    attempt: $log->getAttempt() + 1
                );

                if ($success === true) {
                    $this->logger->info(
                        'Webhook retry succeeded',
                        [
                            'webhook_id' => $webhook->getId(),
                            'log_id'     => $log->getId(),
                        ]
                    );
                } else {
                    $this->logger->warning(
                        'Webhook retry failed',
                        [
                            'webhook_id' => $webhook->getId(),
                            'log_id'     => $log->getId(),
                            'attempt'    => $log->getAttempt() + 1,
                        ]
                    );
                }
            } catch (\Exception $e) {
                $this->logger->error(
                    'Error processing webhook retry',
                    [
                        'log_id' => $log->getId(),
                        'error'  => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach
    }//end run()
}//end class
