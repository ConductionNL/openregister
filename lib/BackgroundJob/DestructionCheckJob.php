<?php

/**
 * OpenRegister Destruction Check Background Job
 *
 * Daily background job that scans for objects due for destruction
 * and generates destruction lists for archivist review.
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

use OCA\OpenRegister\Service\ArchivalService;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Daily background job to check for objects due for archival destruction.
 *
 * Runs once per day (86400 seconds). Finds objects where:
 * - archiefactiedatum has passed
 * - archiefnominatie is 'vernietigen'
 * - archiefstatus is 'nog_te_archiveren'
 *
 * If eligible objects are found, generates a destruction list for review.
 */
class DestructionCheckJob extends TimedJob
{

    /**
     * Daily interval: 24 hours in seconds.
     */
    private const DAILY_INTERVAL = 86400;

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param ITimeFactory    $time    Time factory for parent class
     * @param LoggerInterface $logger  Logger instance
     */
    public function __construct(
        ITimeFactory $time,
        LoggerInterface $logger
    ) {
        parent::__construct(time: $time);

        $this->logger = $logger;

        $this->setInterval(seconds: self::DAILY_INTERVAL);
    }//end __construct()

    /**
     * Execute the destruction check job.
     *
     * Resolves ArchivalService from the DI container and uses it to
     * find objects due for destruction and generate a destruction list.
     *
     * @param mixed $argument Job arguments (unused for recurring jobs)
     *
     * @return void
     */
    protected function run(mixed $argument): void
    {
        $this->logger->info('[DestructionCheckJob] Starting daily destruction check');

        try {
            /** @var ArchivalService $archivalService */
            $archivalService = \OC::$server->get(ArchivalService::class);

            $eligibleObjects = $archivalService->findObjectsDueForDestruction();
            $count = count($eligibleObjects);

            if ($count === 0) {
                $this->logger->info('[DestructionCheckJob] No objects due for destruction');
                return;
            }

            $this->logger->info(
                "[DestructionCheckJob] Found {$count} objects due for destruction, generating list"
            );

            $list = $archivalService->generateDestructionList();

            if ($list !== null) {
                $this->logger->info(
                    "[DestructionCheckJob] Generated destruction list '{$list->getUuid()}' with {$count} objects"
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                '[DestructionCheckJob] Error during destruction check: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }//end try
    }//end run()
}//end class
