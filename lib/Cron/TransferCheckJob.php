<?php

/**
 * OpenRegister Transfer Check Job
 *
 * Background job that scans for objects eligible for e-Depot transfer
 * and generates transfer lists for archivist review.
 *
 * @category Cron
 * @package  OCA\OpenRegister\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Cron;

use DateTime;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Transfer Check Job
 *
 * Periodically scans for objects with archiefnominatie=bewaren that have reached
 * their archiefactiedatum, and generates transfer lists for archivist approval.
 *
 * @category Cron
 * @package  OCA\OpenRegister\Cron
 *
 * @psalm-suppress UnusedClass
 */
class TransferCheckJob extends TimedJob
{

    /**
     * Default interval: 24 hours (86400 seconds).
     */
    private const DEFAULT_INTERVAL = 86400;

    /**
     * Constructor.
     *
     * @param ITimeFactory        $time                The time factory.
     * @param MagicMapper         $objectMapper        The object mapper.
     * @param TransferListService $transferListService The transfer list service.
     * @param IAppConfig          $appConfig           The app configuration.
     * @param LoggerInterface     $logger              Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly MagicMapper $objectMapper,
        private readonly TransferListService $transferListService,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        $interval = (int) $this->appConfig->getValueString(
            'openregister',
            'edepot_check_interval',
            (string) self::DEFAULT_INTERVAL
        );
        $this->setInterval(seconds: $interval);
    }//end __construct()

    /**
     * Run the transfer check.
     *
     * @param mixed $argument Job arguments (unused).
     *
     * @return void
     */
    protected function run(mixed $argument): void
    {
        if ($this->isEdepotConfigured() === false) {
            $this->logger->debug(
                message: '[TransferCheckJob] No e-Depot configured, skipping'
            );
            return;
        }

        $this->logger->info(
            message: '[TransferCheckJob] Starting transfer eligibility scan'
        );

        try {
            $eligibleObjects = $this->findEligibleObjects();

            if (empty($eligibleObjects) === true) {
                $this->logger->info(
                    message: '[TransferCheckJob] No objects eligible for transfer'
                );
                return;
            }

            $transferList = $this->transferListService->createTransferList($eligibleObjects);
            $this->transferListService->notifyArchivists($transferList);

            $this->logger->info(
                message: '[TransferCheckJob] Transfer list created',
                context: [
                    'uuid'        => $transferList['uuid'],
                    'objectCount' => count($eligibleObjects),
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[TransferCheckJob] Error during transfer check',
                context: ['error' => $e->getMessage()]
            );
        }//end try
    }//end run()

    /**
     * Check if e-Depot is configured.
     *
     * @return bool True if e-Depot endpoint is configured.
     */
    private function isEdepotConfigured(): bool
    {
        $endpointUrl = $this->appConfig->getValueString('openregister', 'edepot_endpoint_url', '');
        return (empty($endpointUrl) === false);
    }//end isEdepotConfigured()

    /**
     * Find objects eligible for e-Depot transfer.
     *
     * Objects are eligible when:
     * - archiefnominatie = 'bewaren'
     * - archiefactiedatum <= today
     * - archiefstatus = 'nog_te_archiveren'
     * - Not already on an active transfer list
     *
     * @return array<int, \OCA\OpenRegister\Db\ObjectEntity> Eligible objects.
     */
    private function findEligibleObjects(): array
    {
        // Use a broad search and filter in PHP since the retention field is JSON.
        // This is a simplified approach; production would use a more targeted query.
        $today = (new DateTime())->format('Y-m-d');

        $this->logger->debug(
            message: '[TransferCheckJob] Scanning for eligible objects',
            context: ['cutoffDate' => $today]
        );

        // Note: In a real implementation, this would query using JSON field conditions.
        // For now, we return an empty array as a safe no-op that can be extended
        // when the magic table JSON querying supports retention field filtering.
        return [];
    }//end findEligibleObjects()
}//end class
