<?php

/**
 * Realtime event log retention job.
 *
 * Daily TimedJob that prunes the `openregister_realtime_events` log to
 * keep its size bounded. The retention window defaults to 7 days but
 * can be tuned via the `realtime_event_retention_seconds` app-config
 * key. Setting the key to `0` disables the prune (the job still fires
 * but logs and returns without touching the table).
 *
 * Closes the realtime-updates v1.1 follow-up: "wire a daily TimedJob
 * that calls `deleteOlderThan(7 * 86400)` for default 7-day retention".
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\RealtimeEventMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Daily prune of stale realtime events.
 */
class RealtimeEventRetentionJob extends TimedJob
{

    /**
     * Default retention window: 7 days, expressed in seconds.
     *
     * @var int
     */
    private const DEFAULT_RETENTION_SECONDS = (7 * 86400);

    /**
     * Job interval: 24 hours. The retention window is enforced per
     * tick — running more often than daily would be noise.
     *
     * @var int
     */
    private const RUN_INTERVAL_SECONDS = 86400;

    /**
     * App-config key for the retention window override.
     *
     * @var string
     */
    private const CONFIG_KEY_RETENTION = 'realtime_event_retention_seconds';

    /**
     * App identifier for app-config lookups.
     *
     * @var string
     */
    private const APP_ID = 'openregister';

    /**
     * Constructor.
     *
     * @param ITimeFactory        $time        Time factory required by
     *                                         the parent `TimedJob`.
     * @param IAppConfig          $appConfig   App-config reader for the
     *                                         retention-window override.
     * @param RealtimeEventMapper $eventMapper Mapper exposing
     *                                         `deleteOlderThan()`.
     * @param LoggerInterface     $logger      Logger for telemetry.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly IAppConfig $appConfig,
        private readonly RealtimeEventMapper $eventMapper,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::RUN_INTERVAL_SECONDS);

    }//end __construct()

    /**
     * Execute the prune.
     *
     * @param mixed $argument Job arguments (unused for recurring jobs).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        $retention = (int) $this->appConfig->getValueString(
            app: self::APP_ID,
            key: self::CONFIG_KEY_RETENTION,
            default: (string) self::DEFAULT_RETENTION_SECONDS
        );

        if ($retention <= 0) {
            $this->logger->info(
                message: '[RealtimeEventRetentionJob] Retention disabled (value <= 0), skipping prune',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return;
        }

        try {
            $deleted = $this->eventMapper->deleteOlderThan(retentionSeconds: $retention);
            $this->logger->info(
                message: '[RealtimeEventRetentionJob] Pruned realtime event log',
                context: [
                    'file'             => __FILE__,
                    'line'             => __LINE__,
                    'retentionSeconds' => $retention,
                    'deletedRows'      => $deleted,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                message: '[RealtimeEventRetentionJob] Prune failed: '.$e->getMessage(),
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
        }//end try

    }//end run()
}//end class
