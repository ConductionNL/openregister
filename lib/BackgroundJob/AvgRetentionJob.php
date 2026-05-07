<?php

/**
 * AVG / GDPR retention enforcement TimedJob.
 *
 * Daily job that drives `AvgRetentionService::runRetentionPass()`.
 * Walks every published verwerkingsactiviteit, computes the
 * bewaartermijn cut-off, and soft-deletes any object whose latest
 * audit-trail row predates the cut-off.
 *
 * Operator overrides:
 *   - `avg_retention_enabled` (bool, default: true) — set to `false`
 *     to disable the job (e.g. during a freeze).
 *   - `avg_retention_dry_run` (bool, default: false) — set to `true`
 *     to log what *would* be erased without acting; useful for the
 *     first deployment to verify the catalog's bewaartermijn values
 *     before letting the job destroy data.
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

use OCA\OpenRegister\Service\AvgRetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Daily AVG retention enforcement.
 */
class AvgRetentionJob extends TimedJob
{

    /**
     * Default interval — once per 24 hours.
     *
     * @var int
     */
    private const RUN_INTERVAL_SECONDS = 86400;

    /**
     * App-config key for the enable/disable toggle.
     *
     * @var string
     */
    private const CONFIG_KEY_ENABLED = 'avg_retention_enabled';

    /**
     * App-config key for the dry-run toggle.
     *
     * @var string
     */
    private const CONFIG_KEY_DRY_RUN = 'avg_retention_dry_run';

    /**
     * App identifier for app-config lookups.
     *
     * @var string
     */
    private const APP_ID = 'openregister';

    /**
     * Constructor.
     *
     * @param ITimeFactory        $time             Time factory required by parent.
     * @param IAppConfig          $appConfig        App-config reader.
     * @param AvgRetentionService $retentionService Domain service.
     * @param LoggerInterface     $logger           Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly IAppConfig $appConfig,
        private readonly AvgRetentionService $retentionService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::RUN_INTERVAL_SECONDS);

    }//end __construct()

    /**
     * Drive the retention pass.
     *
     * @param mixed $argument Job arguments (unused for recurring jobs).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        $enabled = filter_var(
            $this->appConfig->getValueString(
                app: self::APP_ID,
                key: self::CONFIG_KEY_ENABLED,
                default: 'true'
            ),
            FILTER_VALIDATE_BOOLEAN
        );

        if ($enabled === false) {
            $this->logger->info(
                message: '[AvgRetentionJob] Retention enforcement disabled (avg_retention_enabled=false), skipping',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return;
        }

        $dryRun = filter_var(
            $this->appConfig->getValueString(
                app: self::APP_ID,
                key: self::CONFIG_KEY_DRY_RUN,
                default: 'false'
            ),
            FILTER_VALIDATE_BOOLEAN
        );

        try {
            $summary = $this->retentionService->runRetentionPass(dryRun: $dryRun);
            $this->logger->info(
                message: '[AvgRetentionJob] Retention pass complete',
                context: [
                    'file'                => __FILE__,
                    'line'                => __LINE__,
                    'dryRun'              => $dryRun,
                    'evaluatedActivities' => $summary['evaluatedActivities'],
                    'skippedActivities'   => $summary['skippedActivities'],
                    'objectsErased'       => $summary['objectsErased'],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                message: '[AvgRetentionJob] Retention pass failed: '.$e->getMessage(),
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
        }//end try

    }//end run()
}//end class
