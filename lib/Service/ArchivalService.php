<?php

/**
 * OpenRegister Archival Service
 *
 * Core business logic for archiving and destruction workflows conforming
 * to Dutch archival standards (MDTO, NEN 2082, Archiefwet 1995).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use DateInterval;
use InvalidArgumentException;
use RuntimeException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\DestructionList;
use OCA\OpenRegister\Db\DestructionListMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SelectionList;
use OCA\OpenRegister\Db\SelectionListMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service for archival and destruction workflow operations.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Required for orchestrating multiple entities
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class ArchivalService
{

    /**
     * Valid archival nomination values.
     */
    public const VALID_NOMINATIONS = ['vernietigen', 'bewaren', 'nog_niet_bepaald'];

    /**
     * Valid archival status values.
     */
    public const VALID_STATUSES = ['nog_te_archiveren', 'gearchiveerd', 'vernietigd', 'overgebracht'];

    /**
     * Constructor.
     *
     * @param IDBConnection         $db                    Database connection
     * @param SelectionListMapper   $selectionListMapper   Selection list mapper
     * @param DestructionListMapper $destructionListMapper Destruction list mapper
     * @param AuditTrailMapper      $auditTrailMapper      Audit trail mapper
     * @param LoggerInterface       $logger                Logger
     */
    public function __construct(
        private IDBConnection $db,
        private SelectionListMapper $selectionListMapper,
        private DestructionListMapper $destructionListMapper,
        private AuditTrailMapper $auditTrailMapper,
        private LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Set retention metadata on an object.
     *
     * Validates the provided retention data against MDTO standards and
     * stores it in the object's retention JSON field.
     *
     * @param ObjectEntity         $object    The object to update
     * @param array<string, mixed> $retention The retention metadata
     *
     * @return ObjectEntity The updated object
     *
     * @throws InvalidArgumentException If retention data is invalid
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function setRetentionMetadata(ObjectEntity $object, array $retention): ObjectEntity
    {
        // Validate archiefnominatie.
        if (isset($retention['archiefnominatie']) === false) {
            $retention['archiefnominatie'] = 'nog_niet_bepaald';
        }

        if (in_array($retention['archiefnominatie'], self::VALID_NOMINATIONS, true) === false) {
            $allowed = implode(', ', self::VALID_NOMINATIONS);
            $value   = $retention['archiefnominatie'];
            throw new InvalidArgumentException(
                "Invalid archiefnominatie '{$value}'. Must be one of: {$allowed}"
            );
        }

        // Validate archiefstatus.
        if (isset($retention['archiefstatus']) === false) {
            $retention['archiefstatus'] = 'nog_te_archiveren';
        }

        if (in_array($retention['archiefstatus'], self::VALID_STATUSES, true) === false) {
            $allowed = implode(', ', self::VALID_STATUSES);
            $value   = $retention['archiefstatus'];
            throw new InvalidArgumentException(
                "Invalid archiefstatus '{$value}'. Must be one of: {$allowed}"
            );
        }

        // Validate archiefactiedatum format if provided.
        if (isset($retention['archiefactiedatum']) === true) {
            $date = DateTime::createFromFormat('Y-m-d', $retention['archiefactiedatum']);
            if ($date === false) {
                // Try ISO 8601 format.
                $date = DateTime::createFromFormat(DateTime::ATOM, $retention['archiefactiedatum']);
                if ($date === false) {
                    throw new InvalidArgumentException(
                        "Invalid archiefactiedatum format. Expected Y-m-d or ISO 8601."
                    );
                }
            }

            $retention['archiefactiedatum'] = $date->format('c');
        }

        // Merge with existing retention data, preserving any extra fields.
        $existingRetention = $object->getRetention() ?? [];
        $mergedRetention   = array_merge($existingRetention, $retention);

        $object->setRetention($mergedRetention);

        return $object;
    }//end setRetentionMetadata()

    /**
     * Calculate the archival action date from a selection list and close date.
     *
     * @param SelectionList $selectionList The selection list entry with retention years
     * @param DateTime      $closeDate     The date the object was closed
     * @param string|null   $schemaUuid    Optional schema UUID for override lookup
     *
     * @return DateTime The calculated archival action date
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-6
     */
    public function calculateArchivalDate(
        SelectionList $selectionList,
        DateTime $closeDate,
        ?string $schemaUuid=null
    ): DateTime {
        $retentionYears = $selectionList->getRetentionYears();

        // Check for schema-level override.
        if ($schemaUuid !== null) {
            $overrides = $selectionList->getSchemaOverrides() ?? [];
            if (isset($overrides[$schemaUuid]) === true) {
                $retentionYears = (int) $overrides[$schemaUuid];
            }
        }

        $archivalDate = clone $closeDate;
        $archivalDate->add(new DateInterval('P'.$retentionYears.'Y'));

        return $archivalDate;
    }//end calculateArchivalDate()

    /**
     * Find objects that are due for destruction.
     *
     * Queries the openregister_objects table for objects where the retention
     * JSON field indicates they are due for destruction.
     *
     * @return ObjectEntity[] Array of objects due for destruction
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-1
     */
    public function findObjectsDueForDestruction(): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_objects')
            ->where(
                $qb->expr()->like(
                    'retention',
                    $qb->createNamedParameter('%"archiefnominatie":"vernietigen"%')
                )
            )
            ->andWhere(
                $qb->expr()->like(
                    'retention',
                    $qb->createNamedParameter('%"archiefstatus":"nog_te_archiveren"%')
                )
            );

        $result   = $qb->executeQuery();
        $entities = [];

        while (($row = $result->fetch()) !== false) {
            $entity = new ObjectEntity();
            $entity->setUuid($row['uuid'] ?? null);
            $entity->setRegister($row['register'] ?? null);
            $entity->setSchema($row['schema'] ?? null);
            $entity->setName($row['name'] ?? null);

            $retention = [];
            if (isset($row['retention']) === true && $row['retention'] !== null) {
                $decoded = json_decode($row['retention'], true);
                if (is_array($decoded) === true) {
                    $retention = $decoded;
                }
            }

            $entity->setRetention($retention);

            // Check if archiefactiedatum is past.
            if (isset($retention['archiefactiedatum']) === true) {
                $actionDate = new DateTime($retention['archiefactiedatum']);
                if ($actionDate <= new DateTime()) {
                    $entities[] = $entity;
                }
            }
        }//end while

        $result->closeCursor();

        return $entities;
    }//end findObjectsDueForDestruction()

    /**
     * Generate a destruction list from objects due for destruction.
     *
     * Finds all objects past their archiefactiedatum with archiefnominatie
     * 'vernietigen' and creates a destruction list for review.
     *
     * @return DestructionList|null The generated list, or null if no objects found
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-2
     */
    public function generateDestructionList(): ?DestructionList
    {
        $eligibleObjects = $this->findObjectsDueForDestruction();

        if (count($eligibleObjects) === 0) {
            $this->logger->info('No objects due for destruction found');
            return null;
        }

        $objectUuids = array_map(
            static function (ObjectEntity $obj): string {
                return $obj->getUuid();
            },
            $eligibleObjects
        );

        $list = new DestructionList();
        $list->setName('Destruction list '.(new DateTime())->format('Y-m-d H:i:s'));
        $list->setObjects($objectUuids);

        return $this->destructionListMapper->createEntry($list);
    }//end generateDestructionList()

    /**
     * Approve a destruction list and permanently delete all objects in it.
     *
     * @param DestructionList $list   The destruction list to approve
     * @param string          $userId The ID of the approving user
     *
     * @return array{destroyed: int, errors: int, list: DestructionList} Result summary
     *
     * @throws InvalidArgumentException If list is not in pending_review status
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-2
     */
    public function approveDestructionList(DestructionList $list, string $userId): array
    {
        if ($list->getStatus() !== DestructionList::STATUS_PENDING_REVIEW) {
            throw new InvalidArgumentException(
                "Cannot approve destruction list with status '{$list->getStatus()}'. Must be 'pending_review'."
            );
        }

        $list->setStatus(DestructionList::STATUS_APPROVED);
        $list->setApprovedBy($userId);
        $list->setApprovedAt(new DateTime());

        $destroyed = 0;
        $errors    = 0;
        $objects   = $list->getObjects() ?? [];

        foreach ($objects as $objectUuid) {
            try {
                $this->destroyObject(objectUuid: $objectUuid, destructionListId: $list->getUuid());
                $destroyed++;
            } catch (\Exception $e) {
                $this->logger->error(
                    "Failed to destroy object {$objectUuid}: ".$e->getMessage(),
                    ['exception' => $e]
                );
                $errors++;
            }
        }//end foreach

        $list->setStatus(DestructionList::STATUS_COMPLETED);
        $list->setNotes(
            ($list->getNotes() ?? '')."\nDestroyed: {$destroyed}, Errors: {$errors}"
        );
        $this->destructionListMapper->updateEntry($list);

        return [
            'destroyed' => $destroyed,
            'errors'    => $errors,
            'list'      => $list,
        ];
    }//end approveDestructionList()

    /**
     * Destroy a single object and create audit trail.
     *
     * @param string $objectUuid        The UUID of the object to destroy
     * @param string $destructionListId The destruction list UUID for audit trail
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Reserved for future audit-trail correlation.
     */
    private function destroyObject(string $objectUuid, string $destructionListId): void
    {
        $qb = $this->db->getQueryBuilder();

        // Fetch the object data for the audit trail.
        $qb->select('*')
            ->from('openregister_objects')
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($objectUuid)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            throw new RuntimeException("Object {$objectUuid} not found");
        }

        // Create an ObjectEntity for audit trail.
        $object = new ObjectEntity();
        $object->setUuid($row['uuid'] ?? null);
        $object->setRegister($row['register'] ?? null);
        $object->setSchema($row['schema'] ?? null);
        $object->setName($row['name'] ?? null);

        // Create audit trail entry.
        $this->auditTrailMapper->createAuditTrail(
            $object,
            null,
            'archival.destroyed'
        );

        // Hard delete the object row.
        $deleteQb = $this->db->getQueryBuilder();
        $deleteQb->delete('openregister_objects')
            ->where($deleteQb->expr()->eq('uuid', $deleteQb->createNamedParameter($objectUuid)));
        $deleteQb->executeStatement();
    }//end destroyObject()

    /**
     * Reject (remove) specific objects from a destruction list.
     *
     * Removed objects have their archiefactiedatum extended by the original
     * retention period from their selection list category.
     *
     * @param DestructionList $list        The destruction list
     * @param string[]        $objectUuids UUIDs of objects to remove from the list
     *
     * @return DestructionList The updated destruction list
     *
     * @throws InvalidArgumentException If list is not in pending_review status
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-2
     */
    public function rejectFromDestructionList(DestructionList $list, array $objectUuids): DestructionList
    {
        if ($list->getStatus() !== DestructionList::STATUS_PENDING_REVIEW) {
            throw new InvalidArgumentException(
                "Cannot modify destruction list with status '{$list->getStatus()}'. Must be 'pending_review'."
            );
        }

        $currentObjects   = $list->getObjects() ?? [];
        $remainingObjects = array_values(array_diff($currentObjects, $objectUuids));

        // Extend archiefactiedatum for rejected objects.
        foreach ($objectUuids as $uuid) {
            $this->extendRetentionForObject(uuid: $uuid);
        }

        $list->setObjects($remainingObjects);

        if (count($remainingObjects) === 0) {
            $list->setStatus(DestructionList::STATUS_CANCELLED);
        }

        return $this->destructionListMapper->updateEntry($list);
    }//end rejectFromDestructionList()

    /**
     * Extend the retention period for a specific object.
     *
     * @param string $uuid The object UUID
     *
     * @return void
     */
    private function extendRetentionForObject(string $uuid): void
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('retention')
                ->from('openregister_objects')
                ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            if ($row === false) {
                return;
            }

            $retention = json_decode($row['retention'] ?? '{}', true) ?? [];

            if (isset($retention['classificatie']) === true) {
                $selectionLists = $this->selectionListMapper->findByCategory(
                    $retention['classificatie']
                );
                if (count($selectionLists) > 0) {
                    $retentionYears = $selectionLists[0]->getRetentionYears();

                    $rawActieDatum = ($retention['archiefactiedatum'] ?? null);
                    $currentDate   = $rawActieDatum !== null ? new DateTime($rawActieDatum) : new DateTime();

                    $newDate = clone $currentDate;
                    $newDate->add(new DateInterval('P'.$retentionYears.'Y'));
                    $retention['archiefactiedatum'] = $newDate->format('c');

                    // Update the retention field.
                    $updateQb = $this->db->getQueryBuilder();
                    $updateQb->update('openregister_objects')
                        ->set('retention', $updateQb->createNamedParameter(json_encode($retention)))
                        ->where($updateQb->expr()->eq('uuid', $updateQb->createNamedParameter($uuid)));
                    $updateQb->executeStatement();
                }
            }//end if
        } catch (\Exception $e) {
            $this->logger->warning(
                "Could not extend retention for rejected object {$uuid}: ".$e->getMessage()
            );
        }//end try
    }//end extendRetentionForObject()
}//end class
