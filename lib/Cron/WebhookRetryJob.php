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
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-13
     */
    public function __construct(
        ITimeFactory $time,
        WebhookMapper $webhookMapper,
        WebhookLogMapper $webhookLogMapper,
        WebhookService $webhookService,
        LoggerInterface $logger
    ) {
        parent::__construct(time: $time);

        $this->webhookMapper    = $webhookMapper;
        $this->webhookLogMapper = $webhookLogMapper;
        $this->webhookService   = $webhookService;
        $this->logger           = $logger;

        // Set interval to 5 minutes.
        $this->setInterval(seconds: self::DEFAULT_INTERVAL);
    }//end __construct()

    /**
     * Run the retry job
     *
     * Finds failed webhook logs that are ready for retry and processes them.
     *
     * @param mixed $argument Job arguments (unused)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-13
     */
    protected function run($argument): void
    {
        $now = new DateTime();

        $this->logger->debug(
            message: '[WebhookRetryJob] Checking for webhook retries',
            context: [
                'file'      => __FILE__,
                'line'      => __LINE__,
                'timestamp' => $now->format('c'),
            ]
        );

        // Find failed logs ready for retry.
        $failedLogs = $this->webhookLogMapper->findFailedForRetry($now);

        if (empty($failedLogs) === true) {
            $this->logger->debug(
                message: '[WebhookRetryJob] No webhook retries needed',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return;
        }

        $this->logger->info(
            message: '[WebhookRetryJob] Processing webhook retries',
            context: [
                'file'  => __FILE__,
                'line'  => __LINE__,
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
                        message: '[WebhookRetryJob] Skipping retry for disabled webhook',
                        context: [
                            'file'       => __FILE__,
                            'line'       => __LINE__,
                            'webhook_id' => $webhook->getId(),
                            'log_id'     => $log->getId(),
                        ]
                    );
                    continue;
                }

                // Check if we've exceeded max retries.
                if ($log->getAttempt() >= $webhook->getMaxRetries()) {
                    $this->logger->warning(
                        message: '[WebhookRetryJob] Webhook retry limit exceeded',
                        context: [
                            'file'        => __FILE__,
                            'line'        => __LINE__,
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
                    message: '[WebhookRetryJob] Retrying webhook delivery',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
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
                        message: '[WebhookRetryJob] Webhook retry succeeded',
                        context: [
                            'file'       => __FILE__,
                            'line'       => __LINE__,
                            'webhook_id' => $webhook->getId(),
                            'log_id'     => $log->getId(),
                        ]
                    );
                    continue;
                }

                $this->logger->warning(
                    message: '[WebhookRetryJob] Webhook retry failed',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'webhook_id' => $webhook->getId(),
                        'log_id'     => $log->getId(),
                        'attempt'    => $log->getAttempt() + 1,
                    ]
                );
            } catch (\Exception $e) {
                $this->logger->error(
                    message: '[WebhookRetryJob] Error processing webhook retry',
                    context: [
                        'file'   => __FILE__,
                        'line'   => __LINE__,
                        'log_id' => $log->getId(),
                        'error'  => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach
    }//end run()
}//end class
