<?php

/**
 * OpenRegister Name Cache Nightly Warmup Background Job
 *
 * Recurring background job that runs every night to warm up the UUID-to-name cache.
 * This ensures optimal facet label resolution performance by pre-populating the
 * distributed name cache with all object names.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Recurring nightly background job for name cache warmup
 *
 * This job runs automatically every night to ensure the UUID-to-name cache
 * is warm and ready for facet label resolution. Pre-populating the cache
 * eliminates cold-start delays for first requests of the day.
 *
 * Features:
 * - Runs daily (24 hour interval)
 * - Warms up distributed name cache for all objects
 * - Loads names from organisations, objects table, and magic tables
 * - Detailed logging and monitoring
 * - Automatic error handling
 */
class NameCacheWarmupJob extends TimedJob
{
    /**
     * Default interval: 24 hours (daily)
     */
    private const DEFAULT_INTERVAL = 24 * 60 * 60;

    /**
     * Constructor
     *
     * Initializes the timed job with the time factory and sets the interval.
     *
     * @param ITimeFactory $time Time factory for parent class
     */
    public function __construct(ITimeFactory $time)
    {
        parent::__construct($time);
        $this->setInterval(self::DEFAULT_INTERVAL);
    }

    /**
     * Execute the nightly name cache warmup job
     *
     * @param mixed $argument Job arguments (unused for recurring jobs)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        $startTime = microtime(true);

        /** @var LoggerInterface $logger */
        $logger = \OC::$server->get(LoggerInterface::class);

        $logger->info(
            '🌙 Name Cache Nightly Warmup Job Started',
            [
                'job_id'         => $this->getId(),
                'scheduled_time' => date('Y-m-d H:i:s'),
                'timezone'       => date_default_timezone_get(),
            ]
        );

        try {
            /** @var CacheHandler $cacheHandler */
            $cacheHandler = \OC::$server->get(CacheHandler::class);

            // Perform cache warmup.
            $namesLoaded = $cacheHandler->warmupNameCache();

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $logger->info(
                '✅ Name Cache Nightly Warmup Job Completed',
                [
                    'job_id'         => $this->getId(),
                    'names_loaded'   => $namesLoaded,
                    'execution_time' => $executionTime.'ms',
                ]
            );
        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $logger->error(
                '❌ Name Cache Nightly Warmup Job Failed',
                [
                    'job_id'         => $this->getId(),
                    'error'          => $e->getMessage(),
                    'execution_time' => $executionTime.'ms',
                ]
            );
        }
    }//end run()
}//end class
