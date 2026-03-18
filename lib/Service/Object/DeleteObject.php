<?php

/**
 * OpenRegister DeleteObject Handler
 *
 * Handler class responsible for removing objects from the system.
 * This handler provides methods for:
 * - Deleting objects from the database
 * - Handling cascading deletes for related objects
 * - Cleaning up associated files and resources
 * - Managing deletion dependencies
 * - Maintaining referential integrity
 * - Tracking deletion operations
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use Exception;
use JsonSerializable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handler class for deleting objects in the OpenRegister application.
 *
 * This handler is responsible for deleting objects from the database,
 * including handling cascading deletes and file cleanup.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Objects
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   GIT: <git_id>
 * @copyright 2024 Conduction b.v.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Delete operations require coordination with multiple services
 */

class DeleteObject
{

    /**
     * Count of cascade-deleted objects from the last deleteObject() call.
     * Reset at the start of each deleteObject() invocation.
     *
     * @var int
     */
    private int $lastCascadeCount = 0;

    /**
     * Audit trail mapper
     *
     * @var AuditTrailMapper
     */
    private AuditTrailMapper $auditTrailMapper;

    /**
     * Settings service
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Logger interface
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Referential integrity service
     *
     * @var ReferentialIntegrityService
     */
    private ReferentialIntegrityService $integrityService;

    /**
     * Database connection for transaction management.
     *
     * @var IDBConnection
     */
    private IDBConnection $db;

    /**
     * Constructor for DeleteObject handler.
     *
     * @param MagicMapper                $objectEntityMapper Object entity data mapper.
     * @param CacheHandler               $cacheHandler       Object cache service for entity and query caching
     * @param IUserSession               $userSession        User session service for tracking who deletes
     * @param AuditTrailMapper           $auditTrailMapper   Audit trail mapper for logs
     * @param SettingsService            $settingsService    Settings service for accessing trail settings
     * @param LoggerInterface            $logger             Logger for error handling
     * @param ReferentialIntegrityService $integrityService  Referential integrity service
     * @param IDBConnection              $db                 Database connection for transactions
     */
    public function __construct(
        private readonly MagicMapper $objectEntityMapper,
        private readonly CacheHandler $cacheHandler,
        private readonly IUserSession $userSession,
        AuditTrailMapper $auditTrailMapper,
        SettingsService $settingsService,
        LoggerInterface $logger,
        ReferentialIntegrityService $integrityService,
        IDBConnection $db
    ) {
        $this->auditTrailMapper = $auditTrailMapper;
        $this->settingsService  = $settingsService;
        $this->logger           = $logger;
        $this->integrityService = $integrityService;
        $this->db               = $db;
    }//end __construct()

    /**
     * Deletes an object and its associated files.
     *
     * @param array|JsonSerializable $object         The object to delete.
     * @param array|null             $cascadeContext  Optional cascade context metadata for audit trail tagging.
     *                                               When non-null, indicates this deletion was triggered by
     *                                               referential integrity enforcement and includes keys like
     *                                               'triggerObject', 'triggerSchema', 'action_type'.
     *
     * @return bool Whether the deletion was successful.
     *
     * @throws Exception If there is an error during deletion.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Soft delete with audit trail requires multiple conditional paths
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple decision paths for soft delete, cache invalidation,
     *                                               and audit trail operations
     *
     * @psalm-suppress UndefinedInterfaceMethod Array access on JsonSerializable handled by type check
     */
    public function delete(array | JsonSerializable $object, ?array $cascadeContext=null): bool
    {
        // Handle ObjectEntity passed from deleteObject() - skip redundant lookup.
        if ($object instanceof ObjectEntity === true) {
            $objectEntity = $object;
            // Get register/schema context for this object.
            $context        = $this->objectEntityMapper->findAcrossAllSources(
                identifier: $objectEntity->getUuid(),
                includeDeleted: true,
                _rbac: false,
                _multitenancy: false
            );
            $registerEntity = $context['register'];
            $schemaEntity   = $context['schema'];
        } else {
            // Handle array input - find object with context (searches across all magic tables).
            // @psalm-suppress UndefinedInterfaceMethod.
            $context        = $this->objectEntityMapper->findAcrossAllSources(
                identifier: $object['id'],
                includeDeleted: false,
                _rbac: false,
                _multitenancy: false
            );
            $objectEntity   = $context['object'];
            $registerEntity = $context['register'];
            $schemaEntity   = $context['schema'];
        }//end if

        // **SOFT DELETE**: Mark object as deleted instead of removing from database.
        // Set deletion metadata with user, timestamp, and organization information.
        $user   = $this->userSession->getUser();
        $userId = 'system';
        if ($user !== null) {
            $userId = $user->getUID();
        }

        // Get the active organization from session at time of deletion for audit trail.
        $activeOrganisation = null;
        if ($user !== null) {
            // Access OrganisationMapper via DI container to get active organization.
            try {
                $organisationMapper = \OC::$server->get(\OCA\OpenRegister\Db\OrganisationMapper::class);
                $activeOrganisation = $organisationMapper->getActiveOrganisationWithFallback($user->getUID());
            } catch (\Exception $e) {
                // If we can't get the active organisation, log and continue with null.
                $this->logger->warning(
                    message: '[DeleteObject] Failed to get active organisation during delete',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
                );
                $activeOrganisation = null;
            }
        }

        $deletionData = [
            'deletedBy'    => $userId,
            'deletedAt'    => (new DateTime())->format(DateTime::ATOM),
            'objectId'     => $objectEntity->getUuid(),
            'organisation' => $activeOrganisation,
        ];

        $objectEntity->setDeleted($deletionData);

        /*
         * Update the object in database (soft delete - keeps record with deleted metadata).
         * Pass register/schema context for magic mapper routing.
         * @psalm-suppress InvalidArgument - ObjectEntity extends Entity
         */

        $result = $this->objectEntityMapper->update(
            entity: $objectEntity,
            register: $registerEntity,
            schema: $schemaEntity
        ) !== null;

        // **CACHE INVALIDATION**: Clear collection and facet caches so soft-deleted objects disappear from regular queries.
        if ($result === true) {
            /*
             * ObjectEntity has getRegister() and getSchema() methods that return string|null.
             * Convert to int|null for invalidateForObjectChange which expects ?int.
             * @var ObjectEntity $objectEntity
             */

            $registerId = $objectEntity->getRegister();
            $schemaId   = $objectEntity->getSchema();

            // Convert register ID to int if numeric.
            $registerIdInt = null;
            if ($registerId !== null && is_numeric($registerId) === true) {
                $registerIdInt = (int) $registerId;
            }

            // Convert schema ID to int if numeric.
            $schemaIdInt = null;
            if ($schemaId !== null && is_numeric($schemaId) === true) {
                $schemaIdInt = (int) $schemaId;
            }

            try {
                $this->cacheHandler->invalidateForObjectChange(
                    object: $objectEntity,
                    operation: 'soft_delete',
                    registerId: $registerIdInt,
                    schemaId: $schemaIdInt
                );
            } catch (\Exception $e) {
                // Gracefully handle cache invalidation errors (e.g., Solr not configured).
                // Soft deletion should succeed even if cache invalidation fails.
            }
        }//end if

        // Create audit trail for delete if audit trails are enabled.
        if ($this->isAuditTrailsEnabled() === true) {
            // Determine the audit action based on cascade context.
            $auditAction = 'delete';
            if ($cascadeContext !== null) {
                $auditAction = $cascadeContext['action_type'] ?? 'referential_integrity.cascade_delete';
            }

            $auditTrail = $this->auditTrailMapper->createAuditTrail(
                old: $objectEntity,
                new: null,
                action: $auditAction
            );

            // If this deletion was triggered by referential integrity, tag the audit entry
            // with cascade context metadata so it can be distinguished from user-initiated deletes.
            if ($cascadeContext !== null && $auditTrail !== null) {
                $changed = $auditTrail->getChanged() ?? [];
                $changed['triggeredBy'] = 'referential_integrity';
                $changed['cascadeContext'] = [
                    'triggerObject' => $cascadeContext['triggerObject'] ?? null,
                    'triggerSchema' => $cascadeContext['triggerSchema'] ?? null,
                    'action_type'   => $cascadeContext['action_type']
                        ?? 'referential_integrity.cascade_delete',
                    'property'      => $cascadeContext['property'] ?? null,
                ];
                $auditTrail->setChanged($changed);
                $this->auditTrailMapper->update($auditTrail);
            }
        }

        return $result;
    }//end delete()

    /**
     * Perform pre-flight deletion analysis for an object.
     *
     * @param ObjectEntity $object The object to analyze.
     *
     * @return DeletionAnalysis The analysis result.
     */
    public function canDelete(ObjectEntity $object): DeletionAnalysis
    {
        return $this->integrityService->canDelete($object);
    }//end canDelete()

    /**
     * Deletes an object by its UUID with optional cascading.
     *
     * Performs referential integrity checks before deletion. If the object's schema
     * has incoming onDelete references from other schemas, walks the dependency graph
     * to detect blockers (RESTRICT) and apply actions (CASCADE, SET_NULL, SET_DEFAULT).
     *
     * @param Register|int|string $register         The register containing the object.
     * @param Schema|int|string   $schema           The schema of the object.
     * @param string              $uuid             The UUID of the object to delete.
     * @param string|null         $originalObjectId The ID of original object for cascading.
     * @param bool                $_rbac            Whether to apply RBAC checks (default: true).
     * @param bool                $_multitenancy    Whether to apply multitenancy filtering (default: true).
     *
     * @return bool Whether the deletion was successful.
     *
     * @throws ReferentialIntegrityException If deletion is blocked by RESTRICT constraints.
     * @throws Exception If there is an error during deletion.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function deleteObject(
        Register | int | string | null $register,
        Schema | int | string | null $schema,
        string $uuid,
        ?string $originalObjectId=null,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): bool {
        // Reset cascade count for root deletions.
        if ($originalObjectId === null) {
            $this->lastCascadeCount = 0;
        }

        // Find object with context (searches across all magic tables).
        $context = $this->objectEntityMapper->findAcrossAllSources(
            identifier: $uuid,
            includeDeleted: true,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );
        $object  = $context['object'];

        // Referential integrity check: only for root deletions (not cascade sub-deletions).
        if ($originalObjectId === null) {
            $schemaId = $object->getSchema();

            // Only run referential integrity if this schema has incoming onDelete references.
            $hasIntegrityActions = $schemaId !== null
                && $this->integrityService->hasIncomingOnDeleteReferences($schemaId) === true;

            if ($hasIntegrityActions === true) {
                $analysis = $this->integrityService->canDelete($object);

                if ($analysis->deletable === false) {
                    // Log RESTRICT block to audit trail before throwing exception.
                    $blockUser    = 'system';
                    $blockUserObj = $this->userSession->getUser();
                    if ($blockUserObj !== null) {
                        $blockUser = $blockUserObj->getUID();
                    }

                    $this->integrityService->logRestrictBlock(
                        objectUuid: $uuid,
                        schemaId: $schemaId,
                        analysis: $analysis,
                        userId: $blockUser
                    );

                    throw new ReferentialIntegrityException(analysis: $analysis);
                }

                // Wrap all cascade operations + the root deletion in a transaction.
                // If any cascade or the root delete fails, everything rolls back.
                $this->db->beginTransaction();
                try {
                    // Apply referential integrity actions (SET_NULL, SET_DEFAULT, CASCADE).
                    $user   = $this->userSession->getUser();
                    $userId = 'system';
                    if ($user !== null) {
                        $userId = $user->getUID();
                    }

                    $activeOrganisation = null;
                    if ($user !== null) {
                        try {
                            $organisationMapper = \OC::$server->get(\OCA\OpenRegister\Db\OrganisationMapper::class);
                            $activeOrganisation = $organisationMapper->getActiveOrganisationWithFallback(
                                $user->getUID()
                            );
                        } catch (\Exception $e) {
                            $activeOrganisation = null;
                        }
                    }

                    $triggerSchemaSlug = null;
                    $contextSchema     = $context['schema'] ?? null;
                    if ($contextSchema instanceof Schema) {
                        $triggerSchemaSlug = $contextSchema->getSlug();
                    }

                    $this->integrityService->applyDeletionActions(
                        $analysis,
                        $userId,
                        $uuid,
                        $activeOrganisation,
                        $triggerSchemaSlug
                    );

                    // Track cascade count for bulk delete reporting.
                    $this->lastCascadeCount = count($analysis->cascadeTargets)
                        + count($analysis->nullifyTargets)
                        + count($analysis->defaultTargets);

                    // Legacy cascade: handle old-style cascade: true properties.
                    $contextRegister = $context['register'] ?? null;
                    $contextSchema   = $context['schema'] ?? null;

                    if ($contextRegister instanceof Register && $contextSchema instanceof Schema) {
                        $this->cascadeDeleteObjects(
                            register: $contextRegister,
                            schema: $contextSchema,
                            object: $object,
                            originalObjectId: $uuid
                        );
                    }

                    // Build cascade context for audit trail tagging on the root object.
                    // This marks the root deletion's audit entry with details about
                    // the referential integrity cascade it triggered.
                    $cascadeCount   = count($analysis->cascadeTargets);
                    $nullifyCount   = count($analysis->nullifyTargets);
                    $defaultCount   = count($analysis->defaultTargets);
                    $rootCascadeCtx = null;
                    if ($cascadeCount > 0 || $nullifyCount > 0 || $defaultCount > 0) {
                        $rootCascadeCtx = [
                            'action_type'        => 'referential_integrity.root_delete',
                            'triggerObject'      => $uuid,
                            'triggerSchema'      => $triggerSchemaSlug,
                            'cascadeDeleteCount' => $cascadeCount,
                            'setNullCount'       => $nullifyCount,
                            'setDefaultCount'    => $defaultCount,
                        ];
                    }

                    // Delete the root object within the same transaction.
                    $result = $this->delete(object: $object, cascadeContext: $rootCascadeCtx);
                    $this->db->commit();
                    return $result;
                } catch (Exception $e) {
                    $this->db->rollBack();
                    $this->logger->error(
                        message: '[DeleteObject] Transaction rolled back: cascade or delete failed',
                        context: [
                            'file'  => __FILE__,
                            'line'  => __LINE__,
                            'uuid'  => $uuid,
                            'error' => $e->getMessage(),
                        ]
                    );
                    throw $e;
                }//end try
            }//end if

            // No referential integrity actions needed — still handle legacy cascade outside transaction.
            $contextRegister = $context['register'] ?? null;
            $contextSchema   = $context['schema'] ?? null;

            if ($contextRegister instanceof Register && $contextSchema instanceof Schema) {
                $this->cascadeDeleteObjects(
                    register: $contextRegister,
                    schema: $contextSchema,
                    object: $object,
                    originalObjectId: $uuid
                );
            }
        }//end if

        try {
            return $this->delete(object: $object);
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[DeleteObject] Delete failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                ]
            );
            return false;
        }//end try
    }//end deleteObject()

    /**
     * Handles cascading deletes for related objects (legacy cascade: true).
     *
     * @param Register     $register         The register containing the object.
     * @param Schema       $schema           The schema of the object.
     * @param ObjectEntity $object           The object being deleted.
     * @param string       $originalObjectId The ID of original object for cascading.
     *
     * @return void
     */
    private function cascadeDeleteObjects(
        Register $register,
        Schema $schema,
        ObjectEntity $object,
        string $originalObjectId
    ): void {
        $properties = $schema->getProperties();
        foreach ($properties ?? [] as $propertyName => $property) {
            if (isset($property['cascade']) === false || $property['cascade'] !== true) {
                continue;
            }

            $value = $object->getObject()[$propertyName] ?? null;
            if ($value === null) {
                continue;
            }

            if (is_array($value) === true) {
                foreach ($value as $id) {
                    $this->deleteObject(
                        register: $register,
                        schema: $schema,
                        uuid: $id,
                        originalObjectId: $originalObjectId
                    );
                }

                continue;
            }

            $this->deleteObject(
                register: $register,
                schema: $schema,
                uuid: $value,
                originalObjectId: $originalObjectId
            );
        }//end foreach
    }//end cascadeDeleteObjects()

    /**
     * Check if audit trails are enabled in the settings
     *
     * @return bool True if audit trails are enabled, false otherwise
     */
    private function isAuditTrailsEnabled(): bool
    {
        try {
            $retentionSettings = $this->settingsService->getRetentionSettingsOnly();
            return $retentionSettings['auditTrailsEnabled'] ?? true;
        } catch (\Exception $e) {
            // If we can't get settings, default to enabled for safety.
            $this->logger->warning(
                message: '[DeleteObject] Failed to check audit trails setting, defaulting to enabled',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return true;
        }
    }//end isAuditTrailsEnabled()

    /**
     * Get the count of cascade-deleted objects from the last deleteObject() call.
     *
     * This includes objects deleted via referential integrity CASCADE actions.
     * Does not include the root object itself (which is counted separately).
     *
     * @return int The number of cascade-deleted objects.
     */
    public function getLastCascadeCount(): int
    {
        return $this->lastCascadeCount;
    }//end getLastCascadeCount()
}//end class
