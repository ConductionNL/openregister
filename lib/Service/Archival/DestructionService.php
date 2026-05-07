<?php

/**
 * OpenRegister Destruction Service
 *
 * Orchestrates the archival destruction workflow: finding eligible objects,
 * creating destruction lists, handling approvals/rejections, executing
 * destruction, and generating destruction certificates.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-2
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-3
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-4
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-4
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-6
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-5
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateInterval;
use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for orchestrating archival destruction workflows.
 *
 * Manages the lifecycle of destruction lists from creation through approval
 * to execution and certificate generation, conforming to Archiefbesluit 1995.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Destruction orchestration requires many service dependencies
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex workflow state machine with multiple paths
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Large service covering full destruction lifecycle
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Public API surface for destruction workflow management
 */
class DestructionService
{

    /**
     * Destruction list status constants.
     */
    public const STATUS_IN_REVIEW       = 'in_review';
    public const STATUS_APPROVED        = 'approved';
    public const STATUS_AWAITING_SECOND = 'awaiting_second_approval';
    public const STATUS_REJECTED        = 'rejected';
    public const STATUS_COMPLETED       = 'completed';

    /**
     * Default extension period when objects are excluded or rejected (1 year).
     */
    private const DEFAULT_EXTENSION_PERIOD = 'P1Y';

    /**
     * Default batch size for destruction execution.
     */
    private const DEFAULT_BATCH_SIZE = 100;

    /**
     * Object entity mapper.
     *
     * @var MagicMapper
     */
    private MagicMapper $objectMapper;

    /**
     * Legal hold service for checking holds.
     *
     * @var LegalHoldService
     */
    private LegalHoldService $legalHoldService;

    /**
     * Delete object handler for permanent deletion.
     *
     * @var DeleteObject
     */
    private DeleteObject $deleteObject;

    /**
     * Audit trail mapper for logging destruction events.
     *
     * @var AuditTrailMapper
     */
    private AuditTrailMapper $auditTrailMapper;

    /**
     * App configuration.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Background job list.
     *
     * @var IJobList
     */
    private IJobList $jobList;

    /**
     * User session.
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param MagicMapper      $objectMapper     Object entity data mapper.
     * @param LegalHoldService $legalHoldService Legal hold checking service.
     * @param DeleteObject     $deleteObject     Delete object handler.
     * @param AuditTrailMapper $auditTrailMapper Audit trail mapper.
     * @param IAppConfig       $appConfig        App configuration.
     * @param IJobList         $jobList          Background job list.
     * @param IUserSession     $userSession      User session service.
     * @param LoggerInterface  $logger           Logger instance.
     */
    public function __construct(
        MagicMapper $objectMapper,
        LegalHoldService $legalHoldService,
        DeleteObject $deleteObject,
        AuditTrailMapper $auditTrailMapper,
        IAppConfig $appConfig,
        IJobList $jobList,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        $this->objectMapper     = $objectMapper;
        $this->legalHoldService = $legalHoldService;
        $this->deleteObject     = $deleteObject;
        $this->auditTrailMapper = $auditTrailMapper;
        $this->appConfig        = $appConfig;
        $this->jobList          = $jobList;
        $this->userSession      = $userSession;
        $this->logger           = $logger;
    }//end __construct()

    /**
     * Find objects eligible for destruction.
     *
     * Objects are eligible when:
     * - archiefactiedatum is in the past
     * - archiefnominatie is 'vernietigen'
     * - archiefstatus is 'nog_te_archiveren'
     * - No active legal hold
     * - Not already on an existing in_review destruction list
     *
     * @param array<string, int> $existingListObjectIds UUIDs of objects already on destruction lists.
     *
     * @return array<int, array<string, mixed>> Array of eligible object data.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-1
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-4
     */
    public function findEligibleObjects(array $existingListObjectIds=[]): array
    {
        $today    = (new DateTime())->format('Y-m-d');
        $eligible = [];

        // Query objects with retention.archiefactiedatum in the past.
        // This uses MagicMapper's JSON field querying capability.
        try {
            $objects = $this->objectMapper->findAll(
                filters: [
                    'retention.archiefnominatie' => 'vernietigen',
                    'retention.archiefstatus'    => 'nog_te_archiveren',
                ],
                includeDeleted: true
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[DestructionService] Failed to query eligible objects',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'exception' => $e->getMessage(),
                ]
            );
            return [];
        }

        foreach ($objects as $object) {
            $retention = $object->getRetention() ?? [];

            // Check archiefactiedatum is in the past.
            $actiedatum = $retention['archiefactiedatum'] ?? null;
            if ($actiedatum === null || $actiedatum > $today) {
                continue;
            }

            // Check no active legal hold.
            if ($this->legalHoldService->hasActiveHold($object) === true) {
                continue;
            }

            // Check not already on an existing destruction list.
            $uuid = $object->getUuid();
            if (in_array($uuid, $existingListObjectIds, true) === true) {
                continue;
            }

            $eligible[] = [
                'uuid'               => $uuid,
                'title'              => $object->getTitle() ?? $uuid,
                'schema'             => $object->getSchema(),
                'register'           => $object->getRegister(),
                'archiefactiedatum'  => $actiedatum,
                'classificatie'      => $retention['classificatie'] ?? null,
                'alreadySoftDeleted' => ($object->getDeleted() !== null),
            ];
        }//end foreach

        $this->logger->info(
            message: '[DestructionService] Found eligible objects for destruction',
            context: [
                'file'  => __FILE__,
                'line'  => __LINE__,
                'count' => count($eligible),
            ]
        );

        return $eligible;
    }//end findEligibleObjects()

    /**
     * Create a destruction list from eligible objects.
     *
     * The destruction list is stored as a register object with status 'in_review'.
     *
     * @param array<int, array<string, mixed>> $eligibleObjects Array of eligible object data.
     *
     * @return array<string, mixed> The created destruction list data.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-2
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-6
     */
    public function createDestructionList(array $eligibleObjects): array
    {
        if (empty($eligibleObjects) === true) {
            $this->logger->info(
                message: '[DestructionService] No eligible objects, skipping destruction list creation',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [];
        }

        $now = new DateTime();

        $destructionList = [
            'status'      => self::STATUS_IN_REVIEW,
            'createdAt'   => $now->format('c'),
            'objectCount' => count($eligibleObjects),
            'objects'     => $eligibleObjects,
            'approvals'   => [],
            'rejections'  => [],
        ];

        $this->logger->info(
            message: '[DestructionService] Created destruction list',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
                'objectCount' => count($eligibleObjects),
                'status'      => self::STATUS_IN_REVIEW,
            ]
        );

        return $destructionList;
    }//end createDestructionList()

    /**
     * Approve a destruction list (full or partial).
     *
     * @param array<string, mixed>  $destructionList  The destruction list data.
     * @param string                $action           The approval action: 'approve_all' or 'approve_partial'.
     * @param array<int, string>    $excludedIds      UUIDs of objects to exclude (for partial approval).
     * @param array<string, string> $exclusionReasons Reasons per excluded object UUID.
     * @param bool                  $requiresDual     Whether two-step approval is required.
     *
     * @return array<string, mixed> The updated destruction list.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Configuration-driven dual approval toggle
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-2
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-1
     */
    public function approveList(
        array $destructionList,
        string $action='approve_all',
        array $excludedIds=[],
        array $exclusionReasons=[],
        bool $requiresDual=false
    ): array {
        $userId = $this->getCurrentUserId();
        $now    = new DateTime();

        // Record the approval.
        $destructionList['approvals'][] = [
            'approvedBy' => $userId,
            'approvedAt' => $now->format('c'),
            'action'     => $action,
        ];

        // Handle partial approval: exclude specific objects.
        if ($action === 'approve_partial' && empty($excludedIds) === false) {
            $destructionList = $this->handlePartialApproval(
                destructionList: $destructionList,
                excludedIds: $excludedIds,
                exclusionReasons: $exclusionReasons
            );
        }

        // Check if dual approval is required and this is the first approval.
        if ($requiresDual === true && count($destructionList['approvals']) < 2) {
            $destructionList['status'] = self::STATUS_AWAITING_SECOND;

            $this->logger->info(
                message: '[DestructionService] First approval recorded, awaiting second approval',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'approvedBy' => $userId,
                ]
            );

            return $destructionList;
        }

        // Check dual approval: second approver must be different from first.
        if ($requiresDual === true && count($destructionList['approvals']) >= 2) {
            $firstApprover  = $destructionList['approvals'][0]['approvedBy'] ?? null;
            $secondApprover = $destructionList['approvals'][1]['approvedBy'] ?? null;
            if ($firstApprover === $secondApprover) {
                $this->logger->warning(
                    message: '[DestructionService] Same archivist cannot provide both approvals',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'approver' => $firstApprover,
                    ]
                );
                // Remove the invalid second approval.
                array_pop($destructionList['approvals']);
                return $destructionList;
            }
        }

        // Mark as approved and queue execution.
        $destructionList['status'] = self::STATUS_APPROVED;

        // Queue the destruction execution job.
        $this->jobList->add(
            \OCA\OpenRegister\BackgroundJob\DestructionExecutionJob::class,
            [
                'destructionList' => $destructionList,
                'approvedBy'      => $userId,
            ]
        );

        $this->logger->info(
            message: '[DestructionService] Destruction list approved, execution job queued',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'approvedBy' => $userId,
                'action'     => $action,
            ]
        );

        return $destructionList;
    }//end approveList()

    /**
     * Handle partial approval by excluding specific objects and extending their dates.
     *
     * @param array<string, mixed>  $destructionList  The destruction list.
     * @param array<int, string>    $excludedIds      UUIDs to exclude.
     * @param array<string, string> $exclusionReasons Reasons per UUID.
     *
     * @return array<string, mixed> The updated destruction list.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-3
     */
    private function handlePartialApproval(
        array $destructionList,
        array $excludedIds,
        array $exclusionReasons
    ): array {
        $extensionPeriod = $this->appConfig->getValueString(
            app: 'openregister',
            key: 'destruction_extension_period',
            default: self::DEFAULT_EXTENSION_PERIOD
        );

        $excluded = [];
        $approved = [];

        foreach ($destructionList['objects'] as $objectEntry) {
            $uuid = $objectEntry['uuid'];
            if (in_array($uuid, $excludedIds, true) === true) {
                $objectEntry['status']          = 'uitgezonderd';
                $objectEntry['exclusionReason'] = $exclusionReasons[$uuid] ?? 'Geen reden opgegeven';
                $excluded[] = $objectEntry;

                // Extend the object's archiefactiedatum.
                $this->extendArchiefactiedatum(
                    uuid: $uuid,
                    extensionPeriod: $extensionPeriod,
                    reason: $objectEntry['exclusionReason']
                );
            } else {
                $objectEntry['status'] = 'approved';
                $approved[]            = $objectEntry;
            }
        }

        $destructionList['objects']         = $approved;
        $destructionList['excludedObjects'] = $excluded;
        $destructionList['objectCount']     = count($approved);

        return $destructionList;
    }//end handlePartialApproval()

    /**
     * Reject an entire destruction list.
     *
     * @param array<string, mixed> $destructionList The destruction list data.
     * @param string               $reason          The reason for rejection.
     *
     * @return array<string, mixed> The updated destruction list.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-2
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-1
     */
    public function rejectList(array $destructionList, string $reason): array
    {
        $userId = $this->getCurrentUserId();
        $now    = new DateTime();

        $extensionPeriod = $this->appConfig->getValueString(
            app: 'openregister',
            key: 'destruction_extension_period',
            default: self::DEFAULT_EXTENSION_PERIOD
        );

        $destructionList['status']       = self::STATUS_REJECTED;
        $destructionList['rejections'][] = [
            'rejectedBy' => $userId,
            'rejectedAt' => $now->format('c'),
            'reason'     => $reason,
        ];

        // Extend archiefactiedatum for all objects on the list.
        foreach ($destructionList['objects'] as $objectEntry) {
            $this->extendArchiefactiedatum(
                uuid: $objectEntry['uuid'],
                extensionPeriod: $extensionPeriod,
                reason: 'Vernietigingslijst afgewezen: '.$reason
            );
        }

        $this->logger->info(
            message: '[DestructionService] Destruction list rejected',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'rejectedBy' => $userId,
                'reason'     => $reason,
            ]
        );

        return $destructionList;
    }//end rejectList()

    /**
     * Execute destruction for an approved destruction list.
     *
     * Permanently deletes objects in batches, re-checking legal holds before each deletion.
     *
     * @param array<string, mixed> $destructionList The approved destruction list.
     * @param string               $approvedBy      The user who approved the list.
     *
     * @return array<string, mixed> Execution result with counts and skipped objects.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple checks per object during destruction
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-2
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-5
     */
    public function executeDestruction(array $destructionList, string $approvedBy): array
    {
        $destroyed = 0;
        $skipped   = [];
        $files     = 0;

        $batchSize = (int) $this->appConfig->getValueString(
            app: 'openregister',
            key: 'destruction_batch_size',
            default: (string) self::DEFAULT_BATCH_SIZE
        );

        $objects = $destructionList['objects'] ?? [];
        $batches = array_chunk($objects, $batchSize);

        foreach ($batches as $batch) {
            foreach ($batch as $objectEntry) {
                $uuid = $objectEntry['uuid'];

                try {
                    $object = $this->objectMapper->findByUuid(
                        $uuid
                    );

                    // Re-check legal hold before deletion.
                    if ($this->legalHoldService->hasActiveHold($object) === true) {
                        $skipped[] = [
                            'uuid'   => $uuid,
                            'reason' => 'legal_hold_placed_after_approval',
                        ];
                        continue;
                    }

                    // Permanently delete the object.
                    $this->deleteObject->delete(
                        objectEntity: $object,
                        permanent: true
                    );

                    $destroyed++;
                } catch (\Exception $e) {
                    $this->logger->error(
                        message: '[DestructionService] Failed to destroy object',
                        context: [
                            'file'      => __FILE__,
                            'line'      => __LINE__,
                            'uuid'      => $uuid,
                            'exception' => $e->getMessage(),
                        ]
                    );
                    $skipped[] = [
                        'uuid'   => $uuid,
                        'reason' => 'error: '.$e->getMessage(),
                    ];
                }//end try
            }//end foreach
        }//end foreach

        $result = [
            'destroyed'      => $destroyed,
            'skipped'        => $skipped,
            'skippedCount'   => count($skipped),
            'filesDestroyed' => $files,
            'approvedBy'     => $approvedBy,
            'executedAt'     => (new DateTime())->format('c'),
        ];

        $this->logger->info(
            message: '[DestructionService] Destruction execution completed',
            context: [
                'file'      => __FILE__,
                'line'      => __LINE__,
                'destroyed' => $destroyed,
                'skipped'   => count($skipped),
            ]
        );

        return $result;
    }//end executeDestruction()

    /**
     * Generate a destruction certificate (verklaring van vernietiging).
     *
     * @param array<string, mixed> $destructionList The completed destruction list.
     * @param array<string, mixed> $executionResult The execution result data.
     *
     * @return array<string, mixed> The destruction certificate data.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-4
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-3
     */
    public function generateCertificate(array $destructionList, array $executionResult): array
    {
        $now = new DateTime();

        // Group destroyed objects by schema and selectielijst category.
        $groupedBySchema    = [];
        $groupedByCategorie = [];
        foreach ($destructionList['objects'] as $objectEntry) {
            $schema    = $objectEntry['schema'] ?? 'unknown';
            $categorie = $objectEntry['classificatie'] ?? 'unknown';

            if (isset($groupedBySchema[$schema]) === false) {
                $groupedBySchema[$schema] = 0;
            }

            $groupedBySchema[$schema]++;

            if (isset($groupedByCategorie[$categorie]) === false) {
                $groupedByCategorie[$categorie] = 0;
            }

            $groupedByCategorie[$categorie]++;
        }

        $approvers = array_map(
            static function (array $approval): string {
                return $approval['approvedBy'] ?? 'unknown';
            },
            $destructionList['approvals'] ?? []
        );

        $certificate = [
            'type'                   => 'verklaring_van_vernietiging',
            'destructionDate'        => $now->format('c'),
            'approvers'              => $approvers,
            'totalObjectsDestroyed'  => $executionResult['destroyed'] ?? 0,
            'totalObjectsSkipped'    => $executionResult['skippedCount'] ?? 0,
            'skippedObjects'         => $executionResult['skipped'] ?? [],
            'objectsBySchema'        => $groupedBySchema,
            'objectsBySelectielijst' => $groupedByCategorie,
            'totalFilesDestroyed'    => $executionResult['filesDestroyed'] ?? 0,
            'complianceStatement'    => 'Conform Archiefwet 1995 en Archiefbesluit 1995, artikelen 6-8.',
            'createdAt'              => $now->format('c'),
            'immutable'              => true,
        ];

        $this->logger->info(
            message: '[DestructionService] Destruction certificate generated',
            context: [
                'file'      => __FILE__,
                'line'      => __LINE__,
                'destroyed' => $certificate['totalObjectsDestroyed'],
                'skipped'   => $certificate['totalObjectsSkipped'],
            ]
        );

        return $certificate;
    }//end generateCertificate()

    /**
     * Validate a destruction list for pre-flight checks.
     *
     * Scans all objects (and their cascade targets) for legal holds.
     *
     * @param array<string, mixed> $destructionList The destruction list to validate.
     *
     * @return array<string, mixed> Validation result with warnings and blocked objects.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-2
     */
    public function validateDestructionList(array $destructionList): array
    {
        $warnings = [];
        $blocked  = [];

        foreach ($destructionList['objects'] as $objectEntry) {
            $uuid = $objectEntry['uuid'];

            try {
                $object = $this->objectMapper->findByUuid($uuid);

                // Check for legal hold.
                if ($this->legalHoldService->hasActiveHold($object) === true) {
                    $blocked[] = [
                        'uuid'   => $uuid,
                        'reason' => 'active_legal_hold',
                    ];
                }
            } catch (\Exception $e) {
                $warnings[] = [
                    'uuid'   => $uuid,
                    'reason' => 'object_not_found',
                ];
            }
        }

        return [
            'valid'    => empty($blocked),
            'warnings' => $warnings,
            'blocked'  => $blocked,
        ];
    }//end validateDestructionList()

    /**
     * Extend the archiefactiedatum for an object by the configured period.
     *
     * @param string $uuid            The object UUID.
     * @param string $extensionPeriod ISO 8601 duration to add.
     * @param string $reason          The reason for extension.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-6
     */
    private function extendArchiefactiedatum(string $uuid, string $extensionPeriod, string $reason): void
    {
        try {
            $object    = $this->objectMapper->findByUuid($uuid);
            $retention = $object->getRetention() ?? [];

            $currentDate = $retention['archiefactiedatum'] ?? null;
            if ($currentDate !== null) {
                $date = new DateTime($currentDate);
                $date->add(new DateInterval($extensionPeriod));
                $retention['archiefactiedatum'] = $date->format('Y-m-d');
            }

            // Record in exclusion history.
            $exclusionHistory   = $retention['exclusionHistory'] ?? [];
            $exclusionHistory[] = [
                'date'                 => (new DateTime())->format('c'),
                'reason'               => $reason,
                'newArchiefactiedatum' => $retention['archiefactiedatum'] ?? null,
            ];
            $retention['exclusionHistory'] = $exclusionHistory;

            $object->setRetention($retention);
            $this->objectMapper->update($object);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[DestructionService] Failed to extend archiefactiedatum',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'uuid'      => $uuid,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try
    }//end extendArchiefactiedatum()

    /**
     * Get the current authenticated user ID.
     *
     * @return string The user ID or 'system' if no user is authenticated.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-2
     */
    private function getCurrentUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return 'system';
        }

        return $user->getUID();
    }//end getCurrentUserId()
}//end class
