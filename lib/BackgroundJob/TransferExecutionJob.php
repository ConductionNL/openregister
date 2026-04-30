<?php

/**
 * OpenRegister Transfer Execution Job
 *
 * Queued background job that executes e-Depot transfers for approved transfer lists.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-33
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-37
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use OCA\OpenRegister\Service\Edepot\Transport\TransportInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Queued job for executing e-Depot transfers.
 *
 * Picks up approved transfer lists and runs the full transfer pipeline
 * (SIP build, transport, status update).
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TransferExecutionJob extends QueuedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory           $time            The time factory.
     * @param EdepotTransferService  $transferService The transfer service.
     * @param SftpTransport          $sftpTransport   SFTP transport.
     * @param RestApiTransport       $restTransport   REST API transport.
     * @param OpenConnectorTransport $ocTransport     OpenConnector transport.
     * @param IAppConfig             $appConfig       The app configuration.
     * @param LoggerInterface        $logger          Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly EdepotTransferService $transferService,
        private readonly SftpTransport $sftpTransport,
        private readonly RestApiTransport $restTransport,
        private readonly OpenConnectorTransport $ocTransport,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Execute the transfer job.
     *
     * @param mixed $argument Job arguments containing the transfer list data.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-37
     */
    protected function run(mixed $argument): void
    {
        if (is_array($argument) === false || empty($argument['transferList']) === true) {
            $this->logger->error(
                message: '[TransferExecutionJob] Invalid job argument: missing transferList'
            );
            return;
        }

        $transferList = $argument['transferList'];

        $this->logger->info(
            message: '[TransferExecutionJob] Starting transfer execution',
            context: ['transferUuid' => ($transferList['uuid'] ?? 'unknown')]
        );

        try {
            $transport = $this->resolveTransport();
            $this->transferService->executeTransfer($transferList, $transport);

            $this->logger->info(
                message: '[TransferExecutionJob] Transfer execution completed',
                context: ['transferUuid' => ($transferList['uuid'] ?? 'unknown')]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[TransferExecutionJob] Transfer execution failed',
                context: [
                    'transferUuid' => ($transferList['uuid'] ?? 'unknown'),
                    'error'        => $e->getMessage(),
                ]
            );
        }
    }//end run()

    /**
     * Resolve the configured transport implementation.
     *
     * @return TransportInterface The transport to use.
     */
    private function resolveTransport(): TransportInterface
    {
        $transportType = $this->appConfig->getValueString('openregister', 'edepot_transport', 'rest_api');

        switch ($transportType) {
            case 'sftp':
                return $this->sftpTransport;
            case 'openconnector':
                return $this->ocTransport;
            case 'rest_api':
            default:
                return $this->restTransport;
        }
    }//end resolveTransport()
}//end class
