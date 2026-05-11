<?php

/**
 * OpenRegister Bulk Legal Hold Background Job
 *
 * Queued background job that places legal holds on all objects in a schema.
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

use DateTime;
use Exception;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\BackgroundJob\QueuedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Queued job for placing legal holds on all objects in a schema.
 */
class BulkLegalHoldJob extends QueuedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory $time Time factory
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-7
     */
    public function __construct(ITimeFactory $time)
    {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Execute the bulk legal hold operation.
     *
     * @param mixed $argument Job arguments with schemaId, reason, userId
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-7
     */
    protected function run($argument): void
    {
        $logger = \OC::$server->get(LoggerInterface::class);

        $schemaId = $argument['schemaId'] ?? null;
        $reason   = $argument['reason'] ?? '';

        if ($schemaId === null) {
            $logger->error('[BulkLegalHoldJob] No schemaId provided');
            return;
        }

        $logger->info('[BulkLegalHoldJob] Placing legal holds on schema: '.$schemaId);

        try {
            $retentionService = \OC::$server->get(RetentionService::class);
            $objectMapper     = \OC::$server->get(MagicMapper::class);

            $objects = $objectMapper->findAll(
                filters: [],
                schema: $schemaId,
                _rbac: false,
                _multitenancy: false
            );

            $count = 0;

            foreach ($objects as $object) {
                $retentionService->placeLegalHold($object, $reason);
                $objectMapper->update($object);
                $count++;
            }

            if ($count > 0) {
                $logger->info('[BulkLegalHoldJob] Placed legal holds on '.$count.' objects');
            }
        } catch (Exception $e) {
            $logger->error('[BulkLegalHoldJob] Error: '.$e->getMessage(), ['exception' => $e]);
        }//end try
    }//end run()
}//end class
