<?php

/**
 * OpenRegister Cache Warmup Background Job
 *
 * Configurable recurring background job that warms up caches to prevent
 * cold-start delays. Default interval: 1 hour, configurable via admin settings.
 * Set interval to 0 to disable.
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
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Configurable recurring background job for cache warmup
 *
 * Warms up the UUID-to-name cache at a configurable interval (default: 1 hour).
 * The interval can be changed via the admin settings panel or set to 0 to disable.
 *
 * @SuppressWarnings(PHPMD.LongVariable) Descriptive variable names improve code readability
 */
class CacheWarmupJob extends TimedJob
{

    /**
     * Default interval: 1 hour.
     */
    private const DEFAULT_INTERVAL = 3600;

    /**
     * App configuration for reading the warmup interval.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param ITimeFactory    $time      Time factory for parent class.
     * @param IAppConfig      $appConfig App configuration for interval setting.
     * @param LoggerInterface $logger    Logger.
     */
    public function __construct(
        ITimeFactory $time,
        IAppConfig $appConfig,
        LoggerInterface $logger
    ) {
        parent::__construct($time);

        $this->appConfig = $appConfig;
        $this->logger    = $logger;

        // Set interval from app configuration.
        $interval = (int) $this->appConfig->getValueString(
            app: 'openregister',
            key: 'cache_warmup_interval',
            default: (string) self::DEFAULT_INTERVAL
        );

        // If interval is 0, disable by setting a very long interval.
        if ($interval === 0) {
            $this->setInterval(seconds: 86400 * 365);
            return;
        }

        $this->setInterval(seconds: $interval);
    }//end __construct()

    /**
     * Execute the cache warmup job
     *
     * @param mixed $argument Job arguments (unused for recurring jobs).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        // Check if the job is disabled.
        $interval = (int) $this->appConfig->getValueString(
            app: 'openregister',
            key: 'cache_warmup_interval',
            default: (string) self::DEFAULT_INTERVAL
        );
        if ($interval === 0) {
            $this->logger->info(
                message: '[CacheWarmupJob] Cache warmup is disabled (interval set to 0), skipping',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return;
        }

        $startTime = microtime(true);

        $this->logger->info(
            message: '[CacheWarmupJob] Cache warmup started',
            context: [
                'file'     => __FILE__,
                'line'     => __LINE__,
                'job_id'   => $this->getId(),
                'interval' => $interval,
            ]
        );

        try {
            // @var CacheHandler $cacheHandler
            $cacheHandler = \OC::$server->get(CacheHandler::class);

            // Warm up the UUID-to-name cache.
            $namesLoaded = $cacheHandler->warmupNameCache();

            $executionTime = round(num: (microtime(true) - $startTime) * 1000, precision: 2);

            // Store last warmup timestamp.
            $this->appConfig->setValueString(
                app: 'openregister',
                key: 'cache_warmup_last_run',
                value: date('Y-m-d H:i:s')
            );

            $this->logger->info(
                message: '[CacheWarmupJob] Cache warmup completed',
                context: [
                    'file'           => __FILE__,
                    'line'           => __LINE__,
                    'job_id'         => $this->getId(),
                    'names_loaded'   => $namesLoaded,
                    'execution_time' => $executionTime.'ms',
                ]
            );
        } catch (\Exception $e) {
            $executionTime = round(num: (microtime(true) - $startTime) * 1000, precision: 2);

            $this->logger->error(
                message: '[CacheWarmupJob] Cache warmup failed: '.$e->getMessage(),
                context: [
                    'file'           => __FILE__,
                    'line'           => __LINE__,
                    'job_id'         => $this->getId(),
                    'error'          => $e->getMessage(),
                    'execution_time' => $executionTime.'ms',
                ]
            );
        }//end try
    }//end run()
}//end class
