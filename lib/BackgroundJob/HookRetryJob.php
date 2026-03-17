<?php

/**
 * OpenRegister Hook Retry Job
 *
 * Background job for retrying schema hooks that failed due to engine unavailability.
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

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Webhook\CloudEventFormatter;
use OCA\OpenRegister\Service\WorkflowEngineRegistry;
use OCA\OpenRegister\WorkflowEngine\WorkflowResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job for retrying queued schema hooks
 *
 * When a hook with `onEngineDown: "queue"` fails because the engine is unreachable,
 * this job is scheduled to retry execution later. On success, it updates the
 * object's `_validationStatus` to "passed". On failure, it re-queues with backoff.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class HookRetryJob extends QueuedJob
{

    /**
     * Maximum number of retry attempts before giving up
     */
    private const MAX_RETRIES = 5;

    /**
     * Constructor for HookRetryJob
     *
     * @param ITimeFactory           $time                Time factory
     * @param MagicMapper     $objectEntityMapper  Object mapper
     * @param SchemaMapper           $schemaMapper        Schema mapper
     * @param WorkflowEngineRegistry $engineRegistry      Engine registry
     * @param CloudEventFormatter    $cloudEventFormatter CloudEvent formatter
     * @param IJobList               $jobList             Job list for re-queuing
     * @param LoggerInterface        $logger              Logger
     */
    public function __construct(
        ITimeFactory $time,
        private readonly MagicMapper $objectEntityMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly WorkflowEngineRegistry $engineRegistry,
        private readonly CloudEventFormatter $cloudEventFormatter,
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Run the retry job
     *
     * @param array<string, mixed> $argument Job arguments containing objectId, schemaId, and hook config
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function run($argument): void
    {
        $objectId = ($argument['objectId'] ?? null);
        $schemaId = ($argument['schemaId'] ?? null);
        $hook     = ($argument['hook'] ?? []);
        $attempt  = ($argument['attempt'] ?? 1);
        $hookId   = ($hook['id'] ?? 'unknown');

        if ($objectId === null || $schemaId === null || empty($hook) === true) {
            $this->logger->error(
                message: '[HookRetryJob] Missing required arguments',
                context: ['argument' => $argument]
            );
            return;
        }

        $this->logger->info(
            message: "[HookRetryJob] Retrying hook '$hookId' for object $objectId (attempt $attempt)",
            context: ['hookId' => $hookId, 'objectId' => $objectId, 'attempt' => $attempt]
        );

        try {
            $object = $this->objectEntityMapper->find(
                identifier: $objectId
            );
            $schema = $this->schemaMapper->find(id: (int) $schemaId);
        } catch (Exception $e) {
            $this->logger->error(
                message: "[HookRetryJob] Could not load object or schema: {$e->getMessage()}",
                context: ['objectId' => $objectId, 'schemaId' => $schemaId]
            );
            return;
        }

        $engineType = ($hook['engine'] ?? '');
        $workflowId = ($hook['workflowId'] ?? '');
        $timeout    = ($hook['timeout'] ?? 30);

        try {
            $engines = $this->engineRegistry->getEnginesByType(engineType: $engineType);
            if (empty($engines) === true) {
                throw new Exception("No engine found for type '$engineType'");
            }

            $adapter = $this->engineRegistry->resolveAdapter(engine: $engines[0]);

            $payload = $this->cloudEventFormatter->formatAsCloudEvent(
                eventType: 'nl.openregister.object.hook-retry',
                payload: [
                    'object'   => $object->getObject(),
                    'schema'   => ($schema->getSlug() ?? $schema->getTitle()),
                    'register' => $object->getRegister(),
                    'action'   => 'retry',
                    'hookMode' => 'sync',
                ],
                source: '/apps/openregister/schemas/'.$schema->getId(),
                subject: 'object:'.($object->getUuid() ?? (string) $object->getId())
            );

            $result = $adapter->executeWorkflow(
                workflowId: $workflowId,
                data: $payload,
                timeout: $timeout
            );

            if ($result->isApproved() === true || $result->isModified() === true) {
                $objectData = ($object->getObject() ?? []);
                $objectData['_validationStatus'] = 'passed';
                unset($objectData['_validationErrors']);

                if ($result->isModified() === true && $result->getData() !== null) {
                    $objectData = array_merge($objectData, $result->getData());
                }

                $object->setObject(object: $objectData);
                $this->objectEntityMapper->update(entity: $object);

                $this->logger->info(
                    message: "[HookRetryJob] Hook '$hookId' succeeded on retry for object $objectId",
                    context: ['hookId' => $hookId, 'objectId' => $objectId]
                );
                return;
            }

            // Rejected or error — keep failed status.
            $this->logger->warning(
                message: "[HookRetryJob] Hook '$hookId' returned status '{$result->getStatus()}' on retry",
                context: [
                    'hookId'   => $hookId,
                    'objectId' => $objectId,
                    'status'   => $result->getStatus(),
                ]
            );
        } catch (Exception $e) {
            $this->logger->warning(
                message: "[HookRetryJob] Retry failed for hook '$hookId': {$e->getMessage()}",
                context: ['hookId' => $hookId, 'objectId' => $objectId, 'attempt' => $attempt]
            );

            if ($attempt < self::MAX_RETRIES) {
                $this->jobList->add(
                    job: self::class,
                    argument: [
                        'objectId' => $objectId,
                        'schemaId' => $schemaId,
                        'hook'     => $hook,
                        'attempt'  => ($attempt + 1),
                    ]
                );
                $this->logger->info(
                    message: "[HookRetryJob] Re-queued hook '$hookId' for attempt ".($attempt + 1),
                    context: ['hookId' => $hookId, 'objectId' => $objectId]
                );
            } else {
                $this->logger->error(
                    message: "[HookRetryJob] Max retries reached for hook '$hookId' on object $objectId",
                    context: ['hookId' => $hookId, 'objectId' => $objectId, 'maxRetries' => self::MAX_RETRIES]
                );
            }
        }//end try
    }//end run()
}//end class
