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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
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
use OCA\OpenRegister\Service\FileService;
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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Delete operations require coordination with multiple services
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complexity at threshold (50) due to integrity + cascade + audit logic
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Integrity + legacy cascade + batched cascade paths live together
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class DeleteObject
{

    /**
     * Count of cascade-deleted objects from the last deleteObject() call.
     * Reset at the start of each deleteObject() invocation.
     *
     * @var integer
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
     * @param MagicMapper                                                      $objectEntityMapper   Object entity data mapper.
     * @param CacheHandler                                                     $cacheHandler         Object entity and query cache
     * @param IUserSession                                                     $userSession          User session service
     * @param AuditTrailMapper                                                 $auditTrailMapper     Audit trail mapper for logs
     * @param SettingsService                                                  $settingsService      Settings service for trail settings
     * @param LoggerInterface                                                  $logger               Logger for error handling
     * @param ReferentialIntegrityService                                      $integrityService     Referential integrity service
     * @param IDBConnection                                                    $db                   Database connection for transactions
     * @param FileService|null                                                 $fileService          File service for object folder cleanup
     * @param \OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry|null $objectSourceRegistry Writable object-source provider registry
     * @param \OCA\OpenRegister\Db\RegisterMapper|null                         $registerMapper       Register mapper for register lookups
     *
     * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) DI constructor wires all delete-path collaborators
     */
    public function __construct(
        private readonly MagicMapper $objectEntityMapper,
        private readonly CacheHandler $cacheHandler,
        private readonly IUserSession $userSession,
        AuditTrailMapper $auditTrailMapper,
        SettingsService $settingsService,
        LoggerInterface $logger,
        ReferentialIntegrityService $integrityService,
        IDBConnection $db,
        private readonly ?FileService $fileService=null,
        private readonly ?\OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry $objectSourceRegistry=null,
        private readonly ?\OCA\OpenRegister\Db\RegisterMapper $registerMapper=null,
    ) {
        $this->auditTrailMapper = $auditTrailMapper;
        $this->settingsService  = $settingsService;
        $this->logger           = $logger;
        $this->integrityService = $integrityService;
        $this->db = $db;
    }//end __construct()

    /**
     * Deletes an object and its associated files.
     *
     * @param array|JsonSerializable $object         The object to delete.
     * @param array|null             $cascadeContext Optional cascade context metadata for audit trail tagging.
     *                                               When non-null, indicates this deletion was triggered by
     *                                               referential integrity enforcement and includes keys like
     *                                               'triggerObject', 'triggerSchema', 'action_type'.
     * @param bool                   $permanent      When true, physically removes the record from the database
     *                                               instead of soft-deleting. Used by archival destruction workflow.
     * @param Register|null          $register       Optional pre-resolved register context. When supplied together
     *                                               with `$schema` and an ObjectEntity `$object`, the redundant
     *                                               cross-table re-find of the object's context is skipped.
     * @param Schema|null            $schema         Optional pre-resolved schema context (see `$register`).
     *
     * @return bool Whether the deletion was successful.
     *
     * @throws Exception If there is an error during deletion.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Soft delete with audit trail requires multiple conditional paths
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple decision paths for soft delete, cache invalidation,
     *                                               and audit trail operations
     *
     * @psalm-suppress UndefinedInterfaceMethod Array access on JsonSerializable handled by type check
     *
     * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function delete(
        array | JsonSerializable $object,
        ?array $cascadeContext=null,
        bool $permanent=false,
        ?Register $register=null,
        ?Schema $schema=null
    ): bool {
        // Handle ObjectEntity passed from deleteObject() - skip redundant lookup.
        // Handle array input - find object with context (searches across all magic tables).
        // NOTE: the ObjectEntity branch MUST be evaluated first. Reading $object['id']
        // on an ObjectEntity raises a fatal \Error ("Cannot use object of type ... as
        // array") because ObjectEntity does not implement ArrayAccess; that \Error is
        // not an \Exception and previously escaped every catch up the stack, surfacing
        // as an HTML 500 to API clients (DELETE on magic-table objects).
        // @psalm-suppress UndefinedInterfaceMethod.
        $identifier = null;
        if ($object instanceof ObjectEntity) {
            $identifier = $object->getUuid();
        }

        if (is_array($object) === true) {
            // BUG-OBJ-11: do not assume an 'id' key exists. Fall back to the
            // canonical identifier shapes and fail with a clear exception rather
            // than emitting an undefined-array-key warning and a null lookup.
            $identifier = ($object['id'] ?? $object['@self']['id'] ?? $object['uuid'] ?? null);
        }

        if ($identifier === null) {
            throw new Exception('Cannot delete object: no identifier (id/@self.id/uuid) found in the supplied data.');
        }

        // PERF: callers that already resolved the object AND its register/schema
        // context (deleteObject() always has both after resolveDeletionContext())
        // pass them in, skipping a full cross-magic-table re-scan per delete.
        $objectEntity   = null;
        $registerEntity = $register;
        $schemaEntity   = $schema;
        if ($object instanceof ObjectEntity === true && $register !== null && $schema !== null) {
            $objectEntity = $object;
        }

        if ($objectEntity === null) {
            $includeDeleted = ($object instanceof ObjectEntity);
            $context        = $this->objectEntityMapper->findAcrossAllSources(
                identifier: $identifier,
                includeDeleted: $includeDeleted,
                _rbac: false,
                _multitenancy: false
            );
            $objectEntity   = $context['object'];
            if ($object instanceof ObjectEntity === true) {
                $objectEntity = $object;
            }

            $registerEntity = $context['register'];
            $schemaEntity   = $context['schema'];
        }

        // **PERMANENT DELETE**: Physical removal from database (archival destruction workflow).
        if ($permanent === true) {
            $this->logger->info(
                message: '[DeleteObject] Permanent deletion requested (archival destruction)',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'uuid' => $objectEntity->getUuid(),
                ]
            );

            // BUG-OBJ-2: permanent deletion must also destroy the Nextcloud folder/files
            // bound to the object. Without this every permanently-deleted object leaks its
            // NC folder forever (storage bloat) and — for the archival `vernietigd`
            // destruction workflow — the file contents survive on disk, which is a
            // compliance issue. Folder cleanup runs BEFORE the DB record is removed so the
            // object still resolves its folder binding.
            $this->deleteObjectFolder(objectEntity: $objectEntity);

            $this->objectEntityMapper->deleteObjectEntity(
                entity: $objectEntity,
                register: $registerEntity,
                schema: $schemaEntity,
                hardDelete: true
            );
            $result = true;

            // Cache invalidation for permanent delete.
            $registerIdForCache = null;
            if (is_numeric($objectEntity->getRegister()) === true) {
                $registerIdForCache = (int) $objectEntity->getRegister();
            }

            $schemaIdForCache = null;
            if (is_numeric($objectEntity->getSchema()) === true) {
                $schemaIdForCache = (int) $objectEntity->getSchema();
            }

            $this->cacheHandler->invalidateForObjectChange(
                registerId: $registerIdForCache,
                schemaId: $schemaIdForCache,
                operation: 'permanent_delete'
            );

            return $result;
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
            } catch (\Throwable $e) {
                // If we can't get the active organisation, log and continue with null.
                // Catches Error too so a null DB in tests (or a missing binding) doesn't
                // abort the whole delete path.
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

        // PERF: snapshot the pre-delete state once and hand it to the mapper as
        // the update's old entity — this skips the mapper's internal pre-update
        // re-find (the entity was freshly fetched by every caller of this method).
        $preDeleteState = clone $objectEntity;

        $objectEntity->setDeleted($deletionData);

        // BUG-OBJ-2: soft delete intentionally LEAVES the bound Nextcloud folder/files
        // in place so the object (and its attachments) can be restored. The folder is
        // only destroyed on permanent delete (above). TODO: if product wants soft-deleted
        // attachments moved to the trash for recoverability, trash the folder here.
        //
        // Update the object in database (soft delete - keeps record with deleted metadata).
        // Pass register/schema context for magic mapper routing.
        // @psalm-suppress InvalidArgument - ObjectEntity extends Entity.
        $result = $this->objectEntityMapper->update(
            entity: $objectEntity,
            register: $registerEntity,
            schema: $schemaEntity,
            oldEntity: $preDeleteState
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

            // PERF: cascade context (referential-integrity tagging) is folded into
            // the initial INSERT by the mapper. Previously this issued a second
            // UPDATE per cascade-tagged row, which also invalidated the hash-chain
            // seal computed over the freshly-inserted row.
            $this->auditTrailMapper->createAuditTrail(
                old: $objectEntity,
                new: null,
                action: $auditAction,
                cascadeContext: $cascadeContext
            );
        }//end if

        return $result;
    }//end delete()

    /**
     * Delete the Nextcloud folder bound to an object (BUG-OBJ-2).
     *
     * Resolves the object's bound folder via FileService and removes it, destroying
     * any attached file contents. No-op when FileService is unavailable (e.g. unit
     * tests), the object has no bound folder, or the folder can no longer be resolved.
     * Failures are logged and swallowed so a missing/legacy folder never blocks the
     * deletion of the database record.
     *
     * @param ObjectEntity $objectEntity The object whose folder must be removed
     *
     * @return void
     *
     * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
     */
    private function deleteObjectFolder(ObjectEntity $objectEntity): void
    {
        if ($this->fileService === null) {
            return;
        }

        // Skip objects that never had a folder bound (nothing to clean up).
        $folderBinding = $objectEntity->getFolder();
        if ($folderBinding === null || $folderBinding === '') {
            return;
        }

        try {
            $folder = $this->fileService->getObjectFolder(objectEntity: $objectEntity);
            if ($folder === null) {
                return;
            }

            $folder->delete();

            $this->logger->info(
                message: '[DeleteObject] Removed Nextcloud folder for permanently deleted object',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'uuid' => $objectEntity->getUuid(),
                ]
            );
        } catch (\Throwable $e) {
            // A missing or legacy folder must not abort the permanent delete.
            $this->logger->warning(
                message: '[DeleteObject] Failed to remove Nextcloud folder during permanent delete',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'uuid'  => $objectEntity->getUuid(),
                    'error' => $e->getMessage(),
                ]
            );
        }//end try
    }//end deleteObjectFolder()

    /**
     * Perform pre-flight deletion analysis for an object.
     *
     * @param ObjectEntity $object The object to analyze.
     *
     * @return DeletionAnalysis The analysis result.
     *
     * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
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
     * @param Register|int|string|null $register         The register containing the object.
     * @param Schema|int|string|null   $schema           The schema of the object.
     * @param string                   $uuid             The UUID of the object to delete.
     * @param string|null              $originalObjectId The ID of original object for cascading.
     * @param bool                     $_rbac            Whether to apply RBAC checks (default: true).
     * @param bool                     $_multitenancy    Whether to apply multitenancy filtering (default: true).
     * @param bool                     $scoped           When true, the caller has guaranteed `$register`
     *                                                   and `$schema` resolve to a specific magic table
     *                                                   and the lookup MUST target only that table —
     *                                                   a UUID in another scope raises DoesNotExistException
     *                                                   without touching any row (#1638).
     *                                                   When false (default), legacy cross-table lookup
     *                                                   via `findAcrossAllSources` is used.
     * @param ObjectEntity|null        $preResolved      Optional already-fetched entity for `$uuid`. Callers
     *                                                   that batch-resolve many objects up front (bulk delete)
     *                                                   pass it together with concrete Register + Schema
     *                                                   entities so the handler skips its own per-object
     *                                                   cross-table lookup. Ignored unless its uuid matches
     *                                                   `$uuid` and both scope entities are concrete.
     *
     * @return bool Whether the deletion was successful.
     *
     * @throws ReferentialIntegrityException                If deletion is blocked by RESTRICT constraints.
     * @throws \OCP\AppFramework\Db\DoesNotExistException   If `$scoped` is true and the UUID is not present
     *                                                       in the given (register, schema) magic table.
     * @throws Exception                                    If there is an error during deletion.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function deleteObject(
        Register | int | string | null $register,
        Schema | int | string | null $schema,
        string $uuid,
        ?string $originalObjectId=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $scoped=false,
        ?ObjectEntity $preResolved=null
    ): bool {
        // Read-only projection guard: a schema served from an external source
        // (x-openregister-object-source) is read-only — deletes are not allowed.
        $objectSource = null;
        if ($schema instanceof Schema) {
            $objectSource = $schema->getObjectSource();
        }

        if ($objectSource !== null) {
            // Opt-in write-through (dbal-virtual-registers-crud): delegate to a
            // WritableObjectSourceProvider when the annotation carries
            // `readOnly: false`; the provider re-verifies the backing source's
            // writable flag live (fail closed). Everything else keeps the v1
            // read-only rejection. Delete RBAC already ran upstream in
            // ObjectService::delete before this dispatch.
            return $this->delegateObjectSourceDelete(
                register: $register,
                schema: $schema,
                objectSource: $objectSource,
                uuid: $uuid
            );
        }

        // Reset cascade count for root deletions.
        if ($originalObjectId === null) {
            $this->lastCascadeCount = 0;
        }

        // Resolve the lookup: when the caller has guaranteed a (register, schema)
        // scope, use the scoped `MagicMapper::find()` path which targets exactly
        // one magic table. Otherwise fall back to the legacy cross-table scan.
        // The scoped path raises DoesNotExistException if the UUID is not in
        // the requested scope — this is the fix for #1638.
        $context = $this->resolveDeletionContext(
            register: $register,
            schema: $schema,
            uuid: $uuid,
            scoped: $scoped,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            preResolved: $preResolved
        );

        $object = $context['object'];

        // Root deletions: check referential integrity and handle cascade.
        if ($originalObjectId === null) {
            $integrityResult = $this->handleIntegrityDeletion(
                object: $object,
                context: $context,
                uuid: $uuid
            );
            if ($integrityResult !== null) {
                return $integrityResult;
            }
        }

        try {
            // Pass the already-resolved scope so delete() skips its own re-find.
            return $this->delete(
                object: $object,
                register: $this->contextRegisterEntity(context: $context),
                schema: $this->contextSchemaEntity(context: $context)
            );
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
     * Resolve the lookup context for a deletion call.
     *
     * Centralises the branch between the scoped MagicMapper::find() path
     * (introduced for #1638) and the legacy `findAcrossAllSources` cross-table
     * scan. The early return on the scoped path keeps the dispatch flat and
     * avoids an else-clause (PHPMD ElseExpression).
     *
     * @param Register|int|string|null $register      Register scope (object, ID, UUID, or slug).
     * @param Schema|int|string|null   $schema        Schema scope.
     * @param string                   $uuid          The UUID being deleted.
     * @param bool                     $scoped        When true, the caller has guaranteed
     *                                                $register/$schema resolve to a specific
     *                                                magic table; lookup MUST target only that table.
     * @param bool                     $_rbac         Whether to apply RBAC checks.
     * @param bool                     $_multitenancy Whether to apply multitenancy filtering.
     * @param ObjectEntity|null        $preResolved   Optional already-fetched entity for $uuid;
     *                                                used (and the lookup skipped entirely) only
     *                                                when its uuid matches and both scope
     *                                                parameters are concrete entities.
     *
     * @return array{object: ObjectEntity, register: Register|int|string, schema: Schema|int|string}
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If $scoped is true and the UUID is
     *                                                     not present in the given magic table.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function resolveDeletionContext(
        Register | int | string | null $register,
        Schema | int | string | null $schema,
        string $uuid,
        bool $scoped,
        bool $_rbac,
        bool $_multitenancy,
        ?ObjectEntity $preResolved=null
    ): array {
        // PERF: a caller that already resolved the entity (bulk delete performs
        // ONE batched cross-table lookup for all UUIDs) passes it in — skip the
        // per-object magic-table scan entirely. Defensive: the entity must match
        // the requested uuid and the scope must be concrete entities.
        if ($preResolved !== null
            && $preResolved->getUuid() === $uuid
            && $register instanceof Register === true
            && $schema instanceof Schema === true
        ) {
            return [
                'object'   => $preResolved,
                'register' => $register,
                'schema'   => $schema,
            ];
        }

        // Scoped path: lookup hits exactly one magic table — a UUID in a
        // different scope raises DoesNotExistException. This is the fix for
        // #1638 (cross-scope silent deletes).
        if ($scoped === true
            && $register instanceof Register === true
            && $schema instanceof Schema === true
        ) {
            $scopedObject = $this->objectEntityMapper->find(
                identifier: $uuid,
                register: $register,
                schema: $schema,
                includeDeleted: true,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );

            return [
                'object'   => $scopedObject,
                'register' => $register,
                'schema'   => $schema,
            ];
        }

        // Legacy path: cross-table scan via findAcrossAllSources — still in
        // use by every unscoped caller. Soft-deprecated; preferred call site
        // is the scoped branch above.
        return $this->objectEntityMapper->findAcrossAllSources(
            identifier: $uuid,
            includeDeleted: true,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );

    }//end resolveDeletionContext()

    /**
     * Extract a concrete Register entity from a deletion context, or null.
     *
     * @param array $context The deletion context (from resolveDeletionContext()).
     *
     * @return Register|null The register entity when the context holds one.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function contextRegisterEntity(array $context): ?Register
    {
        $contextRegister = ($context['register'] ?? null);
        if ($contextRegister instanceof Register === true) {
            return $contextRegister;
        }

        return null;
    }//end contextRegisterEntity()

    /**
     * Extract a concrete Schema entity from a deletion context, or null.
     *
     * @param array $context The deletion context (from resolveDeletionContext()).
     *
     * @return Schema|null The schema entity when the context holds one.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function contextSchemaEntity(array $context): ?Schema
    {
        $contextSchema = ($context['schema'] ?? null);
        if ($contextSchema instanceof Schema === true) {
            return $contextSchema;
        }

        return null;
    }//end contextSchemaEntity()

    /**
     * Handle referential integrity checks and cascade deletion for root deletions.
     *
     * Returns the deletion result if integrity processing handled the delete,
     * or null if no integrity actions were needed (caller should do simple delete).
     *
     * @param ObjectEntity $object  The object being deleted
     * @param array        $context The object context from findAcrossAllSources
     * @param string       $uuid    The object UUID
     *
     * @return bool|null The result, or null if no integrity processing was needed
     *
     * @throws ReferentialIntegrityException If blocked by RESTRICT
     * @throws Exception If cascade transaction fails
     *
     * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
     */
    private function handleIntegrityDeletion(
        ObjectEntity $object,
        array $context,
        string $uuid
    ): ?bool {
        $schemaId           = $object->getSchema();
        $hasIntegrityAction = $schemaId !== null
            && $this->integrityService->hasIncomingOnDeleteReferences($schemaId) === true;

        // Run legacy cascade regardless of integrity actions.
        if ($hasIntegrityAction === false) {
            $this->runLegacyCascade(context: $context, object: $object, uuid: $uuid);
            return null;
        }

        $analysis = $this->integrityService->canDelete($object);
        if ($analysis->deletable === false) {
            $this->logAndThrowRestrict(uuid: $uuid, schemaId: $schemaId, analysis: $analysis);
        }

        return $this->executeIntegrityTransaction(
            object: $object,
            context: $context,
            uuid: $uuid,
            analysis: $analysis
        );

    }//end handleIntegrityDeletion()

    /**
     * Log a RESTRICT block and throw the exception.
     *
     * @param string           $uuid     The object UUID
     * @param string|null      $schemaId The schema ID
     * @param DeletionAnalysis $analysis The analysis result
     *
     * @return void
     *
     * @throws ReferentialIntegrityException Always thrown
     *
     * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
     */
    private function logAndThrowRestrict(string $uuid, ?string $schemaId, DeletionAnalysis $analysis): void
    {
        [$userId] = $this->resolveUserContext();

        $this->integrityService->logRestrictBlock(
            objectUuid: $uuid,
            schemaId: $schemaId,
            analysis: $analysis,
            userId: $userId
        );

        throw new ReferentialIntegrityException(analysis: $analysis);

    }//end logAndThrowRestrict()

    /**
     * Execute integrity cascade actions and root deletion within a transaction.
     *
     * @param ObjectEntity     $object   The object to delete
     * @param array            $context  The object context
     * @param string           $uuid     The object UUID
     * @param DeletionAnalysis $analysis The deletion analysis
     *
     * @return bool True if deletion succeeded
     *
     * @throws Exception If the transaction fails
     *
     * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
     */
    private function executeIntegrityTransaction(
        ObjectEntity $object,
        array $context,
        string $uuid,
        DeletionAnalysis $analysis
    ): bool {
        $this->db->beginTransaction();
        try {
            [$userId, $activeOrg] = $this->resolveUserContext();

            $triggerSlug   = null;
            $contextSchema = $context['schema'] ?? null;
            if ($contextSchema instanceof Schema) {
                $triggerSlug = $contextSchema->getSlug();
            }

            $this->integrityService->applyDeletionActions(
                $analysis,
                $userId,
                $uuid,
                $activeOrg,
                $triggerSlug
            );

            $cCount = count($analysis->cascadeTargets);
            $nCount = count($analysis->nullifyTargets);
            $dCount = count($analysis->defaultTargets);
            $this->lastCascadeCount = ($cCount + $nCount + $dCount);

            $this->runLegacyCascade(context: $context, object: $object, uuid: $uuid);

            $rootCascadeCtx = $this->buildCascadeContext(
                uuid: $uuid,
                triggerSlug: $triggerSlug,
                analysis: $analysis
            );

            $result = $this->delete(
                object: $object,
                cascadeContext: $rootCascadeCtx,
                register: $this->contextRegisterEntity(context: $context),
                schema: $this->contextSchemaEntity(context: $context)
            );
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

    }//end executeIntegrityTransaction()

    /**
     * Run legacy cascade: true deletion if register and schema are available.
     *
     * @param array        $context The object context
     * @param ObjectEntity $object  The object being deleted
     * @param string       $uuid    The object UUID
     *
     * @return void
     *
     * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
     */
    private function runLegacyCascade(array $context, ObjectEntity $object, string $uuid): void
    {
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

    }//end runLegacyCascade()

    /**
     * Build cascade context metadata for audit trail tagging.
     *
     * @param string           $uuid        The root object UUID
     * @param string|null      $triggerSlug The trigger schema slug
     * @param DeletionAnalysis $analysis    The deletion analysis
     *
     * @return array|null The cascade context, or null if no cascades occurred
     *
     * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
     */
    private function buildCascadeContext(string $uuid, ?string $triggerSlug, DeletionAnalysis $analysis): ?array
    {
        $cascadeCount = count($analysis->cascadeTargets);
        $nullifyCount = count($analysis->nullifyTargets);
        $defaultCount = count($analysis->defaultTargets);

        if ($cascadeCount === 0 && $nullifyCount === 0 && $defaultCount === 0) {
            return null;
        }

        return [
            'action_type'        => 'referential_integrity.root_delete',
            'triggerObject'      => $uuid,
            'triggerSchema'      => $triggerSlug,
            'cascadeDeleteCount' => $cascadeCount,
            'setNullCount'       => $nullifyCount,
            'setDefaultCount'    => $defaultCount,
        ];

    }//end buildCascadeContext()

    /**
     * Resolve the current user ID and active organisation.
     *
     * @return array{0: string, 1: mixed} [userId, activeOrganisation]
     *
     * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
     */
    private function resolveUserContext(): array
    {
        $user   = $this->userSession->getUser();
        $userId = 'system';
        $org    = null;

        if ($user !== null) {
            $userId = $user->getUID();
            try {
                $mapper = \OC::$server->get(\OCA\OpenRegister\Db\OrganisationMapper::class);
                $org    = $mapper->getActiveOrganisationWithFallback($user->getUID());
            } catch (\Exception $e) {
                $org = null;
            }
        }

        return [$userId, $org];

    }//end resolveUserContext()

    /**
     * Handles cascading deletes for related objects (legacy cascade: true).
     *
     * PERF: all referenced ids are collected first and resolved with ONE batched
     * cross-table lookup, then soft-deleted with one UPDATE per magic table and
     * one multi-row audit INSERT — instead of running the full per-object delete
     * pipeline (cross-table scan, re-find, row update, audit insert) per id.
     * Ids the uuid-based batch lookup cannot resolve (numeric ids, slugs, URIs)
     * fall back to the legacy per-id pipeline, preserving prior behaviour.
     * Cascade depth is unchanged: children are sub-deletions (originalObjectId
     * set) and never cascade further, exactly like the per-id pipeline.
     *
     * @param Register     $register         The register containing the object.
     * @param Schema       $schema           The schema of the object.
     * @param ObjectEntity $object           The object being deleted.
     * @param string       $originalObjectId The ID of original object for cascading.
     *
     * @return void
     *
     * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
     */
    private function cascadeDeleteObjects(
        Register $register,
        Schema $schema,
        ObjectEntity $object,
        string $originalObjectId
    ): void {
        $cascadeIds = $this->collectCascadeTargetIds(schema: $schema, object: $object);
        if (empty($cascadeIds) === true) {
            return;
        }

        // Resolve every level-one cascade target in one batched lookup.
        $targets = [];
        try {
            $targets = $this->objectEntityMapper->findMultipleAcrossAllMagicTables(
                uuids: $cascadeIds,
                includeDeleted: true
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[DeleteObject] Batched cascade lookup failed; falling back to per-id deletes',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
        }

        $handledIds = $this->batchCascadeSoftDelete(targets: $targets);

        // Legacy per-id pipeline for ids the batch lookup did not resolve.
        foreach ($cascadeIds as $cascadeId) {
            if (isset($handledIds[$cascadeId]) === true) {
                continue;
            }

            $this->deleteObject(
                register: $register,
                schema: $schema,
                uuid: $cascadeId,
                originalObjectId: $originalObjectId
            );
        }
    }//end cascadeDeleteObjects()

    /**
     * Collect the referenced ids of all `cascade: true` schema properties.
     *
     * @param Schema       $schema The schema whose properties are inspected.
     * @param ObjectEntity $object The object holding the reference values.
     *
     * @return string[] Unique, non-empty referenced ids.
     *
     * @psalm-return list<string>
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function collectCascadeTargetIds(Schema $schema, ObjectEntity $object): array
    {
        $ids        = [];
        $properties = $schema->getProperties();
        foreach ($properties ?? [] as $propertyName => $property) {
            if (isset($property['cascade']) === false || $property['cascade'] !== true) {
                continue;
            }

            $value = $object->getObject()[$propertyName] ?? null;
            if ($value === null) {
                continue;
            }

            $candidates = [$value];
            if (is_array($value) === true) {
                $candidates = $value;
            }

            foreach ($candidates as $candidate) {
                if (is_scalar($candidate) === false || (string) $candidate === '') {
                    continue;
                }

                $ids[] = (string) $candidate;
            }
        }//end foreach

        return array_values(array_unique($ids));
    }//end collectCascadeTargetIds()

    /**
     * Soft-delete a batch of resolved cascade targets.
     *
     * Applies the same deletion metadata shape as {@see delete()}, persists via
     * one UPDATE per magic table (MagicMapper::softDeleteMultipleObjectEntities,
     * which also dispatches the per-object updating/updated events the per-id
     * pipeline produced), writes the audit rows with one multi-row INSERT, and
     * invalidates caches per object.
     *
     * @param ObjectEntity[] $targets The batch-resolved cascade target entities.
     *
     * @return array<string, true> Ids taken care of by the batch (keyed by uuid).
     *                             Empty when the batch write failed entirely — the
     *                             caller then falls back to per-id deletes.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function batchCascadeSoftDelete(array $targets): array
    {
        if (empty($targets) === true) {
            return [];
        }

        [$userId, $activeOrganisation] = $this->resolveUserContext();
        $deletedAt = (new DateTime())->format(DateTime::ATOM);

        $handled     = [];
        $oldEntities = [];
        foreach ($targets as $entity) {
            $uuid = $entity->getUuid();
            if ($uuid === null || $uuid === '') {
                continue;
            }

            $handled[$uuid]     = true;
            $oldEntities[$uuid] = clone $entity;
            $entity->setDeleted(
                [
                    'deletedBy'    => $userId,
                    'deletedAt'    => $deletedAt,
                    'objectId'     => $uuid,
                    'organisation' => $activeOrganisation,
                ]
            );
        }

        try {
            $deleted = $this->objectEntityMapper->softDeleteMultipleObjectEntities(
                entities: $targets,
                oldEntities: $oldEntities
            );
        } catch (\Throwable $e) {
            // Nothing was handled — the caller retries every id via the
            // legacy per-id pipeline.
            $this->logger->error(
                message: '[DeleteObject] Batched cascade soft delete failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            return [];
        }

        $this->writeCascadeAuditTrails(deleted: $deleted);
        $this->invalidateCachesForDeleted(deleted: $deleted);

        return $handled;
    }//end batchCascadeSoftDelete()

    /**
     * Write the audit rows for batch-cascade-deleted objects in one bulk INSERT.
     *
     * Row shape matches the per-object path exactly (buildAuditTrail with
     * action `delete` and the post-soft-delete entity as the old state). An
     * audit failure is logged but never aborts the cascade — parity with the
     * per-id pipeline, where a throwing audit write left the object deleted
     * and the cascade running.
     *
     * @param ObjectEntity[] $deleted The successfully soft-deleted entities.
     *
     * @return void
     *
     * @spec openspec/specs/deletion-audit-trail/spec.md
     */
    private function writeCascadeAuditTrails(array $deleted): void
    {
        if (empty($deleted) === true || $this->isAuditTrailsEnabled() === false) {
            return;
        }

        $rows = [];
        foreach ($deleted as $entity) {
            try {
                $rows[] = $this->auditTrailMapper->buildAuditTrail(
                    old: $entity,
                    new: null,
                    action: 'delete'
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    message: '[DeleteObject] Could not build cascade audit row',
                    context: [
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                        'uuid'  => $entity->getUuid(),
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }

        if (empty($rows) === true) {
            return;
        }

        try {
            $this->auditTrailMapper->insertAuditTrails(rows: $rows);
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[DeleteObject] Bulk cascade audit insert failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'count' => count($rows),
                    'error' => $e->getMessage(),
                ]
            );
        }
    }//end writeCascadeAuditTrails()

    /**
     * Invalidate caches per soft-deleted object (parity with delete()).
     *
     * @param ObjectEntity[] $deleted The successfully soft-deleted entities.
     *
     * @return void
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function invalidateCachesForDeleted(array $deleted): void
    {
        foreach ($deleted as $entity) {
            $registerIdInt = null;
            if (is_numeric($entity->getRegister()) === true) {
                $registerIdInt = (int) $entity->getRegister();
            }

            $schemaIdInt = null;
            if (is_numeric($entity->getSchema()) === true) {
                $schemaIdInt = (int) $entity->getSchema();
            }

            try {
                $this->cacheHandler->invalidateForObjectChange(
                    object: $entity,
                    operation: 'soft_delete',
                    registerId: $registerIdInt,
                    schemaId: $schemaIdInt
                );
            } catch (\Exception $e) {
                // Cache invalidation failures never abort a delete (parity with delete()).
            }//end try
        }//end foreach
    }//end invalidateCachesForDeleted()

    /**
     * Check if audit trails are enabled in the settings
     *
     * @return bool True if audit trails are enabled, false otherwise
     *
     * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
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
     *
     * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
     */
    public function getLastCascadeCount(): int
    {
        return $this->lastCascadeCount;
    }//end getLastCascadeCount()

    /**
     * Delegate a delete on an object-source schema to its writable provider.
     *
     * Preserves the v1 read-only rejection unless the annotation carries
     * `readOnly: false` AND a writable provider is registered AND the register
     * resolves. External deletes are hard deletes; zero affected rows surfaces
     * as the same DoesNotExistException (404) an absent native object produces.
     *
     * @param Register|int|string|null $register     The register (entity or identifier).
     * @param Schema                   $schema       The sourced schema.
     * @param array<string, mixed>     $objectSource The `x-openregister-object-source` annotation.
     * @param string                   $uuid         The object id (external key).
     *
     * @return bool True when the external row was deleted.
     *
     * @throws \RuntimeException                          When the schema is not writable (v1 rejection).
     * @throws \OCP\AppFramework\Db\DoesNotExistException When no external row matches the id.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Fail-closed writable checks require explicit branching
     * @SuppressWarnings(PHPMD.NPathComplexity)      Fail-closed writable checks require explicit branching
     */
    private function delegateObjectSourceDelete(
        Register | int | string | null $register,
        Schema $schema,
        array $objectSource,
        string $uuid
    ): bool {
        $provider = null;
        if ($this->objectSourceRegistry !== null) {
            $provider = $this->objectSourceRegistry->get((string) $objectSource['provider']);
        }

        $registerEntity = null;
        if ($register instanceof Register) {
            $registerEntity = $register;
        } else if ($register !== null && $this->registerMapper !== null) {
            try {
                $registerEntity = $this->registerMapper->find($register);
            } catch (\Throwable $e) {
                $registerEntity = null;
            }
        }

        $writableOptIn = (($objectSource['readOnly'] ?? true) === false);
        $writable      = ($provider instanceof \OCA\OpenRegister\Service\ObjectSource\WritableObjectSourceProvider);

        if ($writableOptIn === false || $writable === false || $registerEntity === null) {
            throw new \RuntimeException(
                sprintf(
                    'Schema "%s" is a read-only projection of object-source provider "%s"; deletes are not allowed.',
                    (string) $schema->getSlug(),
                    $objectSource['provider']
                )
            );
        }

        $config = ($objectSource['config'] ?? []);

        // Pre-read for the audit record (best effort).
        $old = $provider->find(register: $registerEntity, schema: $schema, id: $uuid, config: $config);

        $deleted = $provider->remove(register: $registerEntity, schema: $schema, id: $uuid, config: $config);
        if ($deleted === false) {
            throw new \OCP\AppFramework\Db\DoesNotExistException('No external row matches id '.$uuid);
        }

        if ($old !== null) {
            try {
                $this->auditTrailMapper->createAuditTrail(old: $old, new: null, action: 'delete');
            } catch (\Throwable $e) {
                $this->logger->warning(
                    '[DeleteObject] audit trail for external delete on uuid '.$uuid.' could not be recorded: '.$e->getMessage(),
                    ['file' => __FILE__, 'line' => __LINE__]
                );
            }
        }

        return true;
    }//end delegateObjectSourceDelete()
}//end class
