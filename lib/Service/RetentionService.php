<?php

/**
 * OpenRegister Retention Service
 *
 * Orchestrates archival lifecycle operations: metadata population, archiefactiedatum
 * calculation, selectielijst lookup, legal hold management, and destruction coordination.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-60
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-61
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-62
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-70
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-65
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-68
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-67
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use DateInterval;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service for managing retention lifecycle of register objects.
 *
 * Handles MDTO-compliant archival metadata, selectielijst lookups,
 * archiefactiedatum calculation, legal holds, and destruction workflows.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class RetentionService
{

    /**
     * Valid archiefnominatie values.
     */
    private const VALID_NOMINATIES = ['vernietigen', 'bewaren', 'nog_niet_bepaald'];

    /**
     * Valid archiefstatus values.
     */
    private const VALID_STATUSES = [
        'nog_te_archiveren',
        'gearchiveerd',
        'vernietigd',
        'overgebracht',
    ];

    /**
     * Immutable archival statuses (no further updates allowed).
     */
    private const IMMUTABLE_STATUSES = ['vernietigd', 'overgebracht'];

    /**
     * Valid afleidingswijze methods.
     */
    private const VALID_AFLEIDINGSWIJZEN = ['afgehandeld', 'eigenschap', 'termijn'];

    /**
     * Constructor.
     *
     * @param MagicMapper            $objectMapper    Object mapper for queries
     * @param SchemaMapper           $schemaMapper    Schema mapper for lookups
     * @param RegisterMapper         $registerMapper  Register mapper for lookups
     * @param AuditTrailMapper       $auditMapper     Audit trail mapper
     * @param ObjectRetentionHandler $settingsHandler Retention settings handler
     * @param IAppConfig             $appConfig       App configuration
     * @param IUserSession           $userSession     Current user session
     * @param LoggerInterface        $logger          Logger
     * @param IDBConnection          $db              Database connection for eligibility queries
     */
    public function __construct(
        private readonly MagicMapper $objectMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly AuditTrailMapper $auditMapper,
        private readonly ObjectRetentionHandler $settingsHandler,
        private readonly IAppConfig $appConfig,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly IDBConnection $db,
    ) {
    }//end __construct()

    /**
     * Apply archival metadata to an object based on its schema's archive configuration.
     *
     * Called during object creation to populate retention fields from schema defaults
     * and selectielijst mappings.
     *
     * @param ObjectEntity $object The object entity to populate
     * @param Schema       $schema The schema with archive configuration
     *
     * @return ObjectEntity The object with archival metadata applied
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-60
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-70
     */
    public function applyArchivalMetadata(ObjectEntity $object, Schema $schema): ObjectEntity
    {
        $archiveConfig = $schema->getArchive();

        // Skip if archive is not enabled for this schema.
        if (empty($archiveConfig) === true || ($archiveConfig['enabled'] ?? false) === false) {
            return $object;
        }

        $retention = $object->getRetention() ?? [];

        // Do not overwrite if archival metadata already present.
        if (empty($retention['archiefnominatie']) === false) {
            return $object;
        }

        // Try selectielijst lookup first.
        $classificatie      = $archiveConfig['classificatie'] ?? null;
        $selectielijstEntry = null;

        if ($classificatie !== null) {
            $selectielijstEntry = $this->lookupSelectielijstEntry(categorie: $classificatie);
        }

        // Determine nominatie and bewaartermijn.
        if ($selectielijstEntry !== null) {
            $nominatie     = $selectielijstEntry['archiefnominatie'] ?? 'nog_niet_bepaald';
            $bewaartermijn = $selectielijstEntry['bewaartermijn'] ?? null;
            $bron          = $selectielijstEntry['bron'] ?? null;
        } else {
            $nominatie     = $archiveConfig['defaultNominatie'] ?? 'nog_niet_bepaald';
            $bewaartermijn = $archiveConfig['defaultBewaartermijn'] ?? null;
            $bron          = null;
        }

        // Apply schema-level override if configured.
        if (empty($archiveConfig['bewaartermijnOverride']) === false) {
            $bewaartermijn = $archiveConfig['bewaartermijnOverride'];
        }

        // Build archival metadata.
        $retention['archiefnominatie']  = $nominatie;
        $retention['archiefstatus']     = 'nog_te_archiveren';
        $retention['classificatie']     = $classificatie;
        $retention['bewaartermijn']     = $bewaartermijn;
        $retention['selectielijstBron'] = $bron;

        // Calculate archiefactiedatum if bewaartermijn is set.
        if ($bewaartermijn !== null) {
            $retention['archiefactiedatum'] = $this->calculateArchiefactiedatum(
                object: $object,
                schema: $schema,
                bewaartermijn: $bewaartermijn
            );
        }

        $object->setRetention($retention);

        return $object;
    }//end applyArchivalMetadata()

    /**
     * Calculate archiefactiedatum based on the schema's afleidingswijze.
     *
     * @param ObjectEntity $object        The object to calculate for
     * @param Schema       $schema        The schema with afleidingswijze config
     * @param string       $bewaartermijn ISO 8601 duration (e.g., P5Y, P20Y)
     *
     * @return string|null ISO 8601 date string or null if calculation not possible
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-61
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-65
     */
    public function calculateArchiefactiedatum(
        ObjectEntity $object,
        Schema $schema,
        string $bewaartermijn
    ): ?string {
        $archiveConfig   = $schema->getArchive();
        $afleidingswijze = $archiveConfig['afleidingswijze'] ?? 'afgehandeld';

        try {
            $interval = new DateInterval($bewaartermijn);
        } catch (Exception $e) {
            $this->logger->warning(
                '[RetentionService] Invalid bewaartermijn format: '.$bewaartermijn,
                ['exception' => $e]
            );
            return null;
        }

        $brondatum = $this->determineBrondatum(object: $object, schema: $schema, afleidingswijze: $afleidingswijze);

        if ($brondatum === null) {
            // If no brondatum can be determined, use creation date as fallback.
            $brondatum = new DateTime();
        }

        // For 'termijn' method, add procestermijn first.
        if ($afleidingswijze === 'termijn') {
            $procestermijn = $archiveConfig['procestermijn'] ?? null;
            if ($procestermijn !== null) {
                try {
                    $brondatum->add(new DateInterval($procestermijn));
                } catch (Exception $e) {
                    $this->logger->warning(
                        '[RetentionService] Invalid procestermijn format: '.$procestermijn,
                        ['exception' => $e]
                    );
                }
            }
        }

        $brondatum->add($interval);

        return $brondatum->format('Y-m-d');
    }//end calculateArchiefactiedatum()

    /**
     * Determine the brondatum (source date) based on afleidingswijze.
     *
     * @param ObjectEntity $object          The object
     * @param Schema       $schema          The schema
     * @param string       $afleidingswijze The derivation method
     *
     * @return DateTime|null The source date or null
     */
    private function determineBrondatum(
        ObjectEntity $object,
        Schema $schema,
        string $afleidingswijze
    ): ?DateTime {
        $archiveConfig = $schema->getArchive();
        $objectData    = $object->getObject();

        switch ($afleidingswijze) {
            case 'eigenschap':
                $bronEigenschap = $archiveConfig['bronEigenschap'] ?? null;
                if ($bronEigenschap !== null && isset($objectData[$bronEigenschap]) === true) {
                    try {
                        return new DateTime($objectData[$bronEigenschap]);
                    } catch (Exception $e) {
                        $this->logger->warning(
                        '[RetentionService] Cannot parse bronEigenschap date: '.$objectData[$bronEigenschap]
                        );
                    }
                }
                return null;

            case 'afgehandeld':
            case 'termijn':
                // Check for closure date via configured closure field.
                $closureField = $archiveConfig['closureField'] ?? null;
                if ($closureField !== null && isset($objectData[$closureField]) === true) {
                    try {
                        return new DateTime($objectData[$closureField]);
                    } catch (Exception $e) {
                        $this->logger->warning(
                        '[RetentionService] Cannot parse closure date: '.$objectData[$closureField]
                        );
                    }
                }

                // Fallback: use current date (object creation).
                return null;

            default:
                return null;
        }//end switch
    }//end determineBrondatum()

    /**
     * Recalculate archiefactiedatum when a source property changes.
     *
     * @param ObjectEntity $object    The object being updated
     * @param Schema       $schema    The schema
     * @param array        $oldObject The previous object data
     *
     * @return ObjectEntity The object with recalculated dates
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-65
     */
    public function recalculateArchiefactiedatum(
        ObjectEntity $object,
        Schema $schema,
        array $oldObject
    ): ObjectEntity {
        $archiveConfig = $schema->getArchive();

        if (empty($archiveConfig) === true || ($archiveConfig['enabled'] ?? false) === false) {
            return $object;
        }

        $retention = $object->getRetention() ?? [];

        // Skip if no archival metadata present.
        if (empty($retention['archiefnominatie']) === true) {
            return $object;
        }

        // Skip if in immutable status.
        if (in_array($retention['archiefstatus'] ?? '', self::IMMUTABLE_STATUSES, true) === true) {
            return $object;
        }

        $bewaartermijn = $retention['bewaartermijn'] ?? null;
        if ($bewaartermijn === null) {
            return $object;
        }

        // Check if the source property changed.
        $afleidingswijze = $archiveConfig['afleidingswijze'] ?? 'afgehandeld';
        $propertyChanged = false;

        if ($afleidingswijze === 'eigenschap') {
            $bronEigenschap = $archiveConfig['bronEigenschap'] ?? null;
            if ($bronEigenschap !== null) {
                $newData         = $object->getObject();
                $oldVal          = $oldObject[$bronEigenschap] ?? null;
                $newVal          = $newData[$bronEigenschap] ?? null;
                $propertyChanged = ($oldVal !== $newVal);
            }
        } else if (in_array($afleidingswijze, ['afgehandeld', 'termijn'], true) === true) {
            $closureField = $archiveConfig['closureField'] ?? null;
            if ($closureField !== null) {
                $newData         = $object->getObject();
                $oldVal          = $oldObject[$closureField] ?? null;
                $newVal          = $newData[$closureField] ?? null;
                $propertyChanged = ($oldVal !== $newVal);
            }
        }

        if ($propertyChanged === false) {
            return $object;
        }

        $oldDate = $retention['archiefactiedatum'] ?? null;
        $newDate = $this->calculateArchiefactiedatum(object: $object, schema: $schema, bewaartermijn: $bewaartermijn);

        if ($newDate !== null && $newDate !== $oldDate) {
            $retention['archiefactiedatum'] = $newDate;
            $object->setRetention($retention);

            $msg = sprintf(
                '[RetentionService] Recalculated archiefactiedatum for object %s: %s -> %s',
                $object->getUuid(),
                $oldDate,
                $newDate
            );
            $this->logger->info($msg);
        }

        return $object;
    }//end recalculateArchiefactiedatum()

    /**
     * Look up a selectielijst entry by categorie code.
     *
     * @param string $categorie The selectielijst category code (e.g., B1, A1)
     *
     * @return array|null The selectielijst entry data or null if not found
     */
    public function lookupSelectielijstEntry(string $categorie): ?array
    {
        $settings = $this->settingsHandler->getArchivalSettingsOnly();

        $registerId = $settings['selectielijstRegister'] ?? null;
        $schemaId   = $settings['selectielijstSchema'] ?? null;

        if ($registerId === null || $schemaId === null) {
            return null;
        }

        try {
            $register = $this->registerMapper->find((int) $registerId);
            $schema   = $this->schemaMapper->find((int) $schemaId);

            $results = $this->objectMapper->findAll(
                limit: 1,
                filters: ['object->categorie' => $categorie],
                register: $register,
                schema: $schema
            );

            if (empty($results) === true) {
                return null;
            }

            $entry = $results[0];
            return $entry->getObject();
        } catch (Exception $e) {
            $this->logger->warning(
                '[RetentionService] Failed to lookup selectielijst entry for '.$categorie,
                ['exception' => $e]
            );
            return null;
        }//end try
    }//end lookupSelectielijstEntry()

    /**
     * Validate that an object is not in an immutable archival status.
     *
     * @param ObjectEntity $object The object to check
     *
     * @return string|null Error code if immutable, null if mutable
     */
    public function validateNotImmutable(ObjectEntity $object): ?string
    {
        $retention = $object->getRetention() ?? [];
        $status    = $retention['archiefstatus'] ?? null;

        if ($status === 'vernietigd') {
            return 'OBJECT_DESTROYED';
        }

        if ($status === 'overgebracht') {
            return 'OBJECT_TRANSFERRED';
        }

        return null;
    }//end validateNotImmutable()

    /**
     * Place a legal hold on an object.
     *
     * @param ObjectEntity $object The object to place hold on
     * @param string       $reason The reason for the legal hold
     *
     * @return ObjectEntity The object with legal hold applied
     */
    public function placeLegalHold(ObjectEntity $object, string $reason): ObjectEntity
    {
        $retention = $object->getRetention() ?? [];
        $user      = $this->userSession->getUser();
        $userId    = $user !== null ? $user->getUID() : 'system';

        $retention['legalHold'] = [
            'active'     => true,
            'reason'     => $reason,
            'placedBy'   => $userId,
            'placedDate' => (new DateTime())->format('c'),
            'history'    => $retention['legalHold']['history'] ?? [],
        ];

        $object->setRetention($retention);

        return $object;
    }//end placeLegalHold()

    /**
     * Release a legal hold on an object.
     *
     * @param ObjectEntity $object The object to release hold from
     * @param string       $reason The reason for releasing the hold
     *
     * @return ObjectEntity The object with legal hold released
     */
    public function releaseLegalHold(ObjectEntity $object, string $reason): ObjectEntity
    {
        $retention = $object->getRetention() ?? [];
        $legalHold = $retention['legalHold'] ?? null;

        if ($legalHold === null || ($legalHold['active'] ?? false) === false) {
            return $object;
        }

        $user   = $this->userSession->getUser();
        $userId = $user !== null ? $user->getUID() : 'system';

        // Move current hold to history.
        $historyEntry = [
            'reason'        => $legalHold['reason'] ?? '',
            'placedBy'      => $legalHold['placedBy'] ?? '',
            'placedDate'    => $legalHold['placedDate'] ?? '',
            'releasedBy'    => $userId,
            'releasedDate'  => (new DateTime())->format('c'),
            'releaseReason' => $reason,
        ];

        $history   = $legalHold['history'] ?? [];
        $history[] = $historyEntry;

        $retention['legalHold'] = [
            'active'  => false,
            'history' => $history,
        ];

        $object->setRetention($retention);

        return $object;
    }//end releaseLegalHold()

    /**
     * Check if an object has an active legal hold.
     *
     * @param ObjectEntity $object The object to check
     *
     * @return bool True if object has active legal hold
     */
    public function hasActiveLegalHold(ObjectEntity $object): bool
    {
        $retention = $object->getRetention() ?? [];
        return ($retention['legalHold']['active'] ?? false) === true;
    }//end hasActiveLegalHold()

    /**
     * Extend archiefactiedatum by a period for excluded/rejected objects.
     *
     * @param ObjectEntity $object          The object to extend
     * @param string|null  $extensionPeriod ISO 8601 duration (default from settings)
     *
     * @return ObjectEntity The object with extended archiefactiedatum
     */
    public function extendArchiefactiedatum(ObjectEntity $object, ?string $extensionPeriod=null): ObjectEntity
    {
        $retention = $object->getRetention() ?? [];

        if (empty($retention['archiefactiedatum']) === true) {
            return $object;
        }

        if ($extensionPeriod === null) {
            $settings        = $this->settingsHandler->getArchivalSettingsOnly();
            $extensionPeriod = $settings['defaultExtensionPeriod'] ?? 'P1Y';
        }

        try {
            $date = new DateTime($retention['archiefactiedatum']);
            $date->add(new DateInterval($extensionPeriod));
            $retention['archiefactiedatum'] = $date->format('Y-m-d');

            // Store original date if not already stored.
            if (empty($retention['originalArchiefactiedatum']) === true) {
                $retention['originalArchiefactiedatum'] = $retention['archiefactiedatum'];
            }

            $object->setRetention($retention);
        } catch (Exception $e) {
            $this->logger->warning(
                '[RetentionService] Failed to extend archiefactiedatum: '.$e->getMessage()
            );
        }

        return $object;
    }//end extendArchiefactiedatum()

    /**
     * Find objects eligible for destruction.
     *
     * Objects with archiefactiedatum < now, archiefnominatie = vernietigen,
     * archiefstatus = nog_te_archiveren, no active legal hold, and not already
     * on a pending destruction list.
     *
     * @param array $excludeUuids UUIDs to exclude (already on pending lists)
     *
     * @return ObjectEntity[] Array of eligible objects
     */
    public function findEligibleForDestruction(array $excludeUuids=[]): array
    {
        $today = (new DateTime())->format('Y-m-d');

        try {
            // Query objects with archival metadata indicating destruction eligibility.
            $qb = $this->db->getQueryBuilder();

            $qb->select('id')
                ->from('openregister_objects')
                ->where(
                    $qb->expr()->isNotNull('retention')
                );

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            $eligible = [];

            foreach ($rows as $row) {
                try {
                    $object = $this->objectMapper->find(intval($row['id']), null, null, false, false, false);
                } catch (Exception $e) {
                    continue;
                }

                $retention = $object->getRetention() ?? [];

                // Check eligibility criteria.
                if (($retention['archiefnominatie'] ?? '') !== 'vernietigen') {
                    continue;
                }

                if (($retention['archiefstatus'] ?? '') !== 'nog_te_archiveren') {
                    continue;
                }

                $actiedatum = $retention['archiefactiedatum'] ?? null;
                if ($actiedatum === null || $actiedatum > $today) {
                    continue;
                }

                // Skip objects with active legal hold.
                if (($retention['legalHold']['active'] ?? false) === true) {
                    continue;
                }

                // Skip objects already on pending lists.
                if (in_array($object->getUuid(), $excludeUuids, true) === true) {
                    continue;
                }

                $eligible[] = $object;
            }//end foreach

            return $eligible;
        } catch (Exception $e) {
            $this->logger->error(
                '[RetentionService] Failed to find eligible objects for destruction: '.$e->getMessage(),
                ['exception' => $e]
            );
            return [];
        }//end try
    }//end findEligibleForDestruction()

    /**
     * Get UUIDs of objects already on pending destruction lists.
     *
     * @return string[] Array of object UUIDs
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-62
     */
    public function getObjectsOnPendingDestructionLists(): array
    {
        $settings = $this->settingsHandler->getArchivalSettingsOnly();

        $registerId = $settings['destructionListRegister'] ?? null;
        $schemaId   = $settings['destructionListSchema'] ?? null;

        if ($registerId === null || $schemaId === null) {
            return [];
        }

        try {
            $register = $this->registerMapper->find((int) $registerId);
            $schema   = $this->schemaMapper->find((int) $schemaId);

            $pendingLists = $this->objectMapper->findAll(
                filters: [
                    'object->status' => ['in_review', 'approved', 'awaiting_second_approval'],
                ],
                register: $register,
                schema: $schema
            );

            $uuids = [];
            foreach ($pendingLists as $list) {
                $listData = $list->getObject();
                $objects  = $listData['objects'] ?? [];
                foreach ($objects as $obj) {
                    $uuid = $obj['uuid'] ?? null;
                    if ($uuid !== null) {
                        $uuids[] = $uuid;
                    }
                }
            }

            return array_unique($uuids);
        } catch (Exception $e) {
            $this->logger->warning(
                '[RetentionService] Failed to get pending destruction list objects: '.$e->getMessage()
            );
            return [];
        }//end try
    }//end getObjectsOnPendingDestructionLists()

    /**
     * Create a destruction list as a register object.
     *
     * @param ObjectEntity[] $objects The objects to include in the destruction list
     *
     * @return array|null The destruction list data or null on failure
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-68
     */
    public function createDestructionList(array $objects): ?array
    {
        $settings = $this->settingsHandler->getArchivalSettingsOnly();

        $registerId = $settings['destructionListRegister'] ?? null;
        $schemaId   = $settings['destructionListSchema'] ?? null;

        if ($registerId === null || $schemaId === null) {
            $this->logger->warning(
                '[RetentionService] Cannot create destruction list: register/schema not configured'
            );
            return null;
        }

        $user   = $this->userSession->getUser();
        $userId = $user !== null ? $user->getUID() : 'system';

        $objectEntries = [];
        foreach ($objects as $object) {
            $retention = $object->getRetention() ?? [];
            // Detect WOO-published status from object data or metadata.
            $objectData     = $object->getObject() ?? [];
            $isWooPublished = ($objectData['woo_gepubliceerd'] ?? false) === true
                || ($objectData['publicatiestatus'] ?? null) === 'gepubliceerd'
                || ($retention['wooPublished'] ?? false) === true;

            $objectEntries[] = [
                'uuid'              => $object->getUuid(),
                'title'             => $object->getTitle() ?? $object->getUuid(),
                'schema'            => $object->getSchema(),
                'register'          => $object->getRegister(),
                'archiefactiedatum' => $retention['archiefactiedatum'] ?? null,
                'classificatie'     => $retention['classificatie'] ?? null,
                'softDeleted'       => $object->getDeleted() !== null,
                'wooGepubliceerd'   => $isWooPublished,
            ];
        }

        return [
            'status'    => 'in_review',
            'createdBy' => $userId,
            'createdAt' => (new DateTime())->format('c'),
            'objects'   => $objectEntries,
            'excluded'  => [],
            'approvals' => [],
        ];
    }//end createDestructionList()

    /**
     * Generate a destruction certificate after execution.
     *
     * @param array  $destructionList The destruction list data
     * @param int    $destroyedCount  Number of objects destroyed
     * @param string $executedAt      ISO 8601 timestamp of execution
     *
     * @return array The destruction certificate data
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-62
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-67
     */
    public function generateDestructionCertificate(
        array $destructionList,
        int $destroyedCount,
        string $executedAt
    ): array {
        // Group destroyed objects by schema and classificatie.
        $grouped = [];
        foreach ($destructionList['objects'] ?? [] as $obj) {
            $key = ($obj['schema'] ?? 'unknown').'/'.($obj['classificatie'] ?? 'unknown');
            if (isset($grouped[$key]) === false) {
                $grouped[$key] = [
                    'schema'        => $obj['schema'] ?? 'unknown',
                    'classificatie' => $obj['classificatie'] ?? 'unknown',
                    'count'         => 0,
                ];
            }

            $grouped[$key]['count']++;
        }

        return [
            'type'                => 'verklaring_van_vernietiging',
            'destructionDate'     => $executedAt,
            'approvedBy'          => array_column($destructionList['approvals'] ?? [], 'userId'),
            'destructionListUuid' => $destructionList['uuid'] ?? null,
            'totalDestroyed'      => $destroyedCount,
            'groupedBySchema'     => array_values($grouped),
            'selectielijstBron'   => $this->extractSelectielijstBron(destructionList: $destructionList),
            'complianceStatement' => 'Vernietiging conform Archiefwet 1995 en Archiefbesluit 1995',
            'immutable'           => true,
        ];
    }//end generateDestructionCertificate()

    /**
     * Extract selectielijst bron references from a destruction list.
     *
     * @param array $destructionList The destruction list data
     *
     * @return string[] Unique selectielijst bron references
     */
    private function extractSelectielijstBron(array $destructionList): array
    {
        $bronnen = [];
        foreach ($destructionList['objects'] ?? [] as $obj) {
            $bron = $obj['selectielijstBron'] ?? null;
            if ($bron !== null) {
                $bronnen[] = $bron;
            }
        }

        return array_values(array_unique($bronnen));
    }//end extractSelectielijstBron()
}//end class
