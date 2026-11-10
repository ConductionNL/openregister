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
use DateTimeImmutable;
use Exception;
use JsonSerializable;
use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\FileFolderServiceInterface;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Handler class for deleting objects in the OpenRegister application.
 *
 * This handler is responsible for deleting objects from the database,
 * including handling cascading deletes and file cleanup.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Objects
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
class DeleteObject {

	/**
	 * Count of cascade-deleted objects from the last deleteObject() call.
	 * Reset at the start of each deleteObject() invocation.
	 *
	 * @var integer
	 */
	private int $lastCascadeCount = 0;

	/**
	 * Cascade targets the archival obligation kept live during the last root delete.
	 *
	 * Reset at the start of each root deleteObject() invocation, exactly like
	 * $lastCascadeCount, so a reader never picks up a previous delete's answer.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $lastRetainedTargets = [];

	/**
	 * Uuids whose delete guards a root delete asked before its cascade ran.
	 *
	 * The delete that follows takes its answer from here instead of asking the
	 * guards (and every other ObjectDeletingEvent listener) a second time.
	 *
	 * @var array<string, true>
	 */
	private array $guardsAsked = [];

	/**
	 * Audit trail mapper
	 *
	 * @var AuditTrailMapperInterface
	 */
	private AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * Settings service
	 *
	 * @var SettingsServiceInterface
	 */
	private SettingsServiceInterface $settingsService;

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
	 * @param MagicMapperInterface $objectEntityMapper Object entity data mapper.
	 * @param CacheHandlerInterface $cacheHandler Object entity and query cache
	 * @param IUserSession $userSession User session service
	 * @param AuditRecording $audit Audit trail mapper for logs, and the logger for error handling
	 * @param SettingsServiceInterface $settingsService Settings service for trail settings
	 * @param ReferentialIntegrityService $integrityService Referential integrity service
	 * @param IDBConnection $db Database connection for transactions
	 * @param OrganisationMapperInterface $organisationMapper Resolves the deleting user's active organisation for the audit trail
	 * @param FileFolderServiceInterface|null $fileService File service for object folder cleanup
	 * @param \OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry|null $objectSourceRegistry Writable object-source provider registry
	 * @param \OCA\OpenRegister\Db\RegisterMapperInterface|null $registerMapper Register mapper for register lookups
	 * @param DeletionWindowService|null $windowService Resolves and states the recovery window
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) DI constructor wires all delete-path collaborators
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectEntityMapper,
		private readonly CacheHandlerInterface $cacheHandler,
		private readonly IUserSession $userSession,
		AuditRecording $audit,
		SettingsServiceInterface $settingsService,
		ReferentialIntegrityService $integrityService,
		IDBConnection $db,
		private readonly OrganisationMapperInterface $organisationMapper,
		private readonly ?FileFolderServiceInterface $fileService = null,
		private readonly ?\OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry $objectSourceRegistry = null,
		private readonly ?\OCA\OpenRegister\Db\RegisterMapperInterface $registerMapper = null,
		private readonly ?DeletionWindowService $windowService = null,
	) {
		$this->auditTrailMapper = $audit->trail;
		$this->settingsService = $settingsService;
		$this->logger = $audit->logger;
		$this->integrityService = $integrityService;
		$this->db = $db;
	}//end __construct()

	/**
	 * Deletes an object and its associated files.
	 *
	 * @param array|JsonSerializable $object The object to delete.
	 * @param RemovalOptions|null $options The cascade tagging, the permanence and the already-resolved
	 *                                     table; null removes softly with no cascade tagging.
	 *
	 * @return bool Whether the deletion was successful.
	 *
	 * @throws Exception If there is an error during deletion.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function delete(array|JsonSerializable $object, ?RemovalOptions $options=null): bool {
		$options    = ($options ?? new RemovalOptions());
		$identifier = $this->deletionIdentifier(object: $object);

		$given  = self::givenDeletionTarget(object: $object, options: $options);
		$target = ($given ?? $this->lookUpDeletionTarget(object: $object, identifier: $identifier));

		// Asked before the folder goes and before the row is marked deleted.
		$uuid = (string)$target->object->getUuid();
		if (isset($this->guardsAsked[$uuid]) === false) {
			$this->objectEntityMapper->assertDeletable(entity: $target->object);
		}

		unset($this->guardsAsked[$uuid]);

		if ($options->permanent === true) {
			$this->deletePermanently(target: $target);
			return true;
		}

		$this->softDelete(target: $target, cascadeContext: $options->cascadeContext);
		return true;
	}//end delete()

	/**
	 * The deletion target the caller already resolved, when it handed in the
	 * entity together with its register/schema context.
	 *
	 * PERF: callers that already resolved the object AND its register/schema
	 * context (deleteObject() always has both after resolveDeletionContext())
	 * pass them in, skipping a full cross-magic-table re-scan per delete.
	 *
	 * @param array|JsonSerializable $object  The object to delete.
	 * @param RemovalOptions         $options The removal options.
	 *
	 * @return DeletionTarget|null The target, or null when it must be looked up.
	 */
	private static function givenDeletionTarget(array|JsonSerializable $object, RemovalOptions $options): ?DeletionTarget {
		if ($object instanceof ObjectEntity === true && $options->table !== null) {
			return new DeletionTarget(object: $object, register: $options->table->register, schema: $options->table->schema);
		}

		return null;
	}//end givenDeletionTarget()

	/**
	 * The identifier of the object handed to delete().
	 *
	 * The ObjectEntity branch is evaluated first: ObjectEntity does not implement
	 * ArrayAccess, so reading `$object['id']` on it raises a fatal \Error.
	 *
	 * @param array|JsonSerializable $object The object to delete.
	 *
	 * @return string|int The identifier.
	 *
	 * @throws Exception When the supplied data carries no identifier.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function deletionIdentifier(array|JsonSerializable $object): string|int {
		$identifier = null;
		if ($object instanceof ObjectEntity) {
			$identifier = $object->getUuid();
		}

		if (is_array($object) === true) {
			// BUG-OBJ-11: do not assume an 'id' key exists. Fall back to the
			// canonical identifier shapes.
			$identifier = ($object['id'] ?? $object['@self']['id'] ?? $object['uuid'] ?? null);
		}

		if ($identifier === null) {
			throw new Exception('Cannot delete object: no identifier (id/@self.id/uuid) found in the supplied data.');
		}

		return $identifier;
	}//end deletionIdentifier()

	/**
	 * Find the object and its register/schema context across all sources.
	 *
	 * A passed ObjectEntity is kept as the object to delete; only its context is looked up,
	 * and soft-deleted rows are included in that lookup.
	 *
	 * @param array|JsonSerializable $object     The object to delete.
	 * @param string|int             $identifier Its identifier.
	 *
	 * @return DeletionTarget The object with its register and schema.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function lookUpDeletionTarget(array|JsonSerializable $object, string|int $identifier): DeletionTarget {
		$context = $this->objectEntityMapper->findAcrossAllSources(
			identifier: $identifier,
			includeDeleted: ($object instanceof ObjectEntity),
			_rbac: false,
			_multitenancy: false
		);

		$objectEntity = $context['object'];
		if ($object instanceof ObjectEntity === true) {
			$objectEntity = $object;
		}

		return new DeletionTarget(object: $objectEntity, register: $context['register'], schema: $context['schema']);
	}//end lookUpDeletionTarget()

	/**
	 * Physically remove an object, its bound folder and its cached queries (archival destruction).
	 *
	 * BUG-OBJ-2: the bound Nextcloud folder is destroyed BEFORE the record is removed, so the
	 * object still resolves its folder binding. A removed draft leaves one audit-trail entry
	 * without its content (decision 226); every other permanent delete is recorded by the
	 * workflow that asked for it.
	 *
	 * @param DeletionTarget $target The object to remove.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function deletePermanently(DeletionTarget $target): void {
		$objectEntity = $target->object;
		$this->logger->info(
			message: '[DeleteObject] Permanent deletion requested (archival destruction)',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $objectEntity->getUuid(),
			]
		);

		$this->deleteObjectFolder(objectEntity: $objectEntity);

		$this->objectEntityMapper->deleteObjectEntity(
			entity: $objectEntity,
			register: $target->register,
			schema: $target->schema,
			hardDelete: true,
			// The guards were asked in delete(), before the folder was removed.
			dispatchEvents: false
		);

		if ($objectEntity->isDraft() === true) {
			(new DraftRemovalRecord(auditTrail: $this->auditTrailMapper, logger: $this->logger))->write(
				removed: new RemovedDraft(draft: $objectEntity, register: $target->register, schema: $target->schema)
			);
		}

		$this->cacheHandler->invalidateForObjectChange(
			registerId: $target->registerCacheId(),
			schemaId: $target->schemaCacheId(),
			operation: 'permanent_delete'
		);
	}//end deletePermanently()

	/**
	 * Mark an object deleted for the session user, then invalidate caches and audit the delete.
	 *
	 * Soft delete intentionally LEAVES the bound Nextcloud folder in place so the object and its
	 * attachments can be restored (BUG-OBJ-2).
	 *
	 * @param DeletionTarget            $target         The object to mark deleted.
	 * @param array<string, mixed>|null $cascadeContext Referential-integrity cascade metadata, if any.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function softDelete(DeletionTarget $target, ?array $cascadeContext): void {
		$objectEntity = $target->object;
		$deletionData = $this->deletionMetadata(target: $target);

		// PERF: the pre-delete snapshot is the update's old entity, which skips the
		// mapper's internal pre-update re-find.
		$preDeleteState = clone $objectEntity;

		$objectEntity->setDeleted($deletionData);

		// @psalm-suppress InvalidArgument - ObjectEntity extends Entity.
		$this->objectEntityMapper->update(
			entity: $objectEntity,
			register: $target->register,
			schema: $target->schema,
			oldEntity: $preDeleteState
		);

		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.update');

		try {
			$this->cacheHandler->invalidateForObjectChange(
				object: $objectEntity,
				operation: 'soft_delete',
				registerId: $target->registerCacheId(),
				schemaId: $target->schemaCacheId()
			);
		} catch (\Exception $e) {
			// Soft deletion succeeds even if cache invalidation fails.
		}

		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.cache');

		if ($this->isAuditTrailsEnabled() === true) {
			$auditAction = 'delete';
			if ($cascadeContext !== null) {
				$auditAction = $cascadeContext['action_type'] ?? 'referential_integrity.cascade_delete';
			}

			// The cascade context is folded into the audit trail's initial INSERT by the mapper.
			$this->auditTrailMapper->createAuditTrail(
				old: $objectEntity,
				new: null,
				action: $auditAction,
				cascadeContext: $cascadeContext
			);
		}

		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.audit');
	}//end softDelete()

	/**
	 * The deleted metadata of a soft delete: the session user, the moment, the user's active
	 * organisation and, when a window service is wired, the stated recovery window.
	 *
	 * @param DeletionTarget $target The object being deleted.
	 *
	 * @return array<string, mixed> The deleted metadata.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function deletionMetadata(DeletionTarget $target): array {
		$user = $this->userSession->getUser();
		$userId = 'system';
		if ($user !== null) {
			$userId = $user->getUID();
		}

		// The active organisation of the deleting user. Error is caught too, so a missing
		// database never aborts the delete.
		$activeOrganisation = null;
		if ($user !== null) {
			try {
				$activeOrganisation = $this->organisationMapper->getActiveOrganisationWithFallback($user->getUID());
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[DeleteObject] Failed to get active organisation during delete',
					context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
				);
			}
		}

		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.org');

		$deletedAt = new DateTime();
		$deletionData = [
			'deletedBy' => $userId,
			'deletedAt' => $deletedAt->format(DateTime::ATOM),
			'objectId' => $target->object->getUuid(),
			'organisation' => $activeOrganisation,
		];

		if ($this->windowService === null) {
			return $deletionData;
		}

		return array_merge(
			$deletionData,
			$this->windowService->openWindow(
				schema: $target->schema,
				deletedAt: DateTimeImmutable::createFromMutable($deletedAt)
			)
		);
	}//end deletionMetadata()

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
	private function deleteObjectFolder(ObjectEntity $objectEntity): void {
		// Skip objects that never had a folder bound (nothing to clean up).
		if ($this->fileService === null || self::hasBoundFolder(objectEntity: $objectEntity) === false) {
			return;
		}

		try {
			$this->removeObjectFolder(fileService: $this->fileService, objectEntity: $objectEntity);
		} catch (\Throwable $e) {
			// A missing or legacy folder must not abort the permanent delete.
			$this->logFolderRemovalFailure(objectEntity: $objectEntity, error: $e);
		}
	}//end deleteObjectFolder()

	/**
	 * Whether the object has a folder bound to it.
	 *
	 * @param ObjectEntity $objectEntity The object.
	 *
	 * @return bool
	 */
	private static function hasBoundFolder(ObjectEntity $objectEntity): bool {
		$folderBinding = $objectEntity->getFolder();
		return ($folderBinding !== null && $folderBinding !== '');
	}//end hasBoundFolder()

	/**
	 * Delete the object's Nextcloud folder, when it resolves, and log the removal.
	 *
	 * @param FileFolderServiceInterface $fileService  The file service.
	 * @param ObjectEntity               $objectEntity The object.
	 *
	 * @return void
	 */
	private function removeObjectFolder(FileFolderServiceInterface $fileService, ObjectEntity $objectEntity): void {
		$folder = $fileService->getObjectFolder(objectEntity: $objectEntity);
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
	}//end removeObjectFolder()

	/**
	 * Log a folder that could not be removed during a permanent delete.
	 *
	 * @param ObjectEntity $objectEntity The object.
	 * @param \Throwable   $error        Why it could not be removed.
	 *
	 * @return void
	 */
	private function logFolderRemovalFailure(ObjectEntity $objectEntity, \Throwable $error): void {
		$this->logger->warning(
			message: '[DeleteObject] Failed to remove Nextcloud folder during permanent delete',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $objectEntity->getUuid(),
				'error' => $error->getMessage(),
			]
		);
	}//end logFolderRemovalFailure()

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
	public function canDelete(ObjectEntity $object): DeletionAnalysis {
		return $this->integrityService->canDelete($object);
	}//end canDelete()

	/**
	 * Deletes an object by its UUID with optional cascading.
	 *
	 * Performs referential integrity checks before deletion. If the object's schema
	 * has incoming onDelete references from other schemas, walks the dependency graph
	 * to detect blockers (RESTRICT) and apply actions (CASCADE, SET_NULL, SET_DEFAULT).
	 *
	 * @param DeletionRequest $request The register and schema scope, the uuid and the options. With
	 *                                 `scoped` the register and schema resolve to one magic table and the
	 *                                 lookup MUST target only that table: a UUID in another scope raises
	 *                                 DoesNotExistException without touching any row (#1638); otherwise the
	 *                                 legacy cross-table lookup via `findAcrossAllSources` is used.
	 *                                 `preResolved` lets a bulk caller skip the per-object lookup; it is
	 *                                 ignored unless its uuid matches and both scope values are entities.
	 * @param bool $_rbac Whether to apply RBAC checks (default: true).
	 * @param bool $_multitenancy Whether to apply multitenancy filtering (default: true).
	 *
	 *                        The `permanent` option destroys the row instead of tombstoning it.
	 *                        Default false, which is the soft delete every existing caller already gets.
	 *
	 *                        This is NOT a convenience. A soft delete leaves the row in place with
	 *                        `_deleted` set, and the magic table's `_uuid` unique index carries NO
	 *                        `WHERE _deleted IS NULL` predicate — so the tombstone goes on holding
	 *                        the identifier forever. The create path disagrees: it looks for a
	 *                        conflict with `includeDeleted: false`, finds nothing, and proceeds,
	 *                        only for the write underneath to be refused. The caller is then told
	 *                        `An object with identifier "x" already exists` about an object every
	 *                        read says does not exist (openregister#2459).
	 *
	 *                        That contradiction is survivable for records, which are archived and
	 *                        never re-created under the same identifier. It is fatal for a CLAIM —
	 *                        a lock, a slot, a lease — whose identifier is deliberately a pure
	 *                        function of the thing being claimed, because that is what makes two
	 *                        concurrent claimants collide. Such an identifier cannot be varied per
	 *                        attempt without giving up the mutual exclusion it exists for, so a
	 *                        claim that cannot destroy its own row is a claim that can be taken
	 *                        exactly once, ever. Measured: hydra's sequencer locked
	 *                        `zz-lock-openregister-1561`, released it, and was still refused a
	 *                        fresh claim eight hours later by its own tombstone.
	 *
	 *                        Reserve it for rows whose identifier must become reusable. For
	 *                        anything that is a record, the soft delete is what makes a mistake
	 *                        recoverable, and that remains the default.
	 *
	 * @return bool Whether the deletion was successful.
	 *
	 * @throws ReferentialIntegrityException If deletion is blocked by RESTRICT constraints.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If `scoped` is set and the UUID is not present
	 *                                                    in the given (register, schema) magic table.
	 * @throws HookStoppedException When a delete guard refuses.
	 * @throws Exception If there is an error during deletion.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function deleteObject(DeletionRequest $request, bool $_rbac=true, bool $_multitenancy=true): bool {
		// Read-only projection guard: a schema served from an external source
		// (x-openregister-object-source) is read-only — deletes are not allowed,
		// unless the annotation opts in to write-through (see
		// delegateObjectSourceDelete()). Delete RBAC already ran upstream in
		// ObjectService::delete before this dispatch.
		$schema = $request->schema;
		if ($schema instanceof Schema && $schema->getObjectSource() !== null) {
			return $this->delegateObjectSourceDelete(schema: $schema, request: $request);
		}

		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.handler.in');

		// Reset cascade count for root deletions.
		if ($request->options->isRoot() === true) {
			[$this->lastCascadeCount, $this->lastRetainedTargets, $this->guardsAsked] = [0, [], []];
		}

		$context = $this->resolveDeletionContext(request: $request, _rbac: $_rbac, _multitenancy: $_multitenancy);

		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.ctx');

		return $this->deleteResolved(run: new DeletionRun(context: $context, uuid: $request->uuid), options: $request->options);
	}//end deleteObject()

	/**
	 * Run integrity handling for a root deletion, then delete the resolved object.
	 *
	 * @param DeletionRun     $run     The resolved context and the uuid asked for.
	 * @param DeletionOptions $options Whether this is a root deletion and whether it is permanent.
	 *
	 * @return bool Whether the deletion was successful; false when the delete itself failed.
	 *
	 * @throws ReferentialIntegrityException If deletion is blocked by RESTRICT constraints.
	 * @throws HookStoppedException When a delete guard refuses.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function deleteResolved(DeletionRun $run, DeletionOptions $options): bool {
		// Root deletions: check referential integrity and handle cascade.
		if ($options->isRoot() === true) {
			$integrityResult = $this->handleIntegrityDeletion(run: $run);
			if ($integrityResult !== null) {
				return $integrityResult;
			}
		}

		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.integrity');

		try {
			// Pass the already-resolved scope so delete() skips its own re-find.
			return $this->delete(object: $run->object(), options: new RemovalOptions(permanent: $options->permanent, table: $run->table()));
		} catch (HookStoppedException $e) {
			// A guard's refusal carries its own message and status for the caller.
			throw $e;
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[DeleteObject] Delete failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $run->uuid,
					'error' => $e->getMessage(),
				]
			);
			return false;
		}//end try
	}//end deleteResolved()

	/**
	 * Resolve the lookup context for a deletion call.
	 *
	 * Centralises the branch between the pre-resolved entity, the scoped
	 * MagicMapper::find() path (introduced for #1638) and the legacy
	 * `findAcrossAllSources` cross-table scan.
	 *
	 * @param DeletionRequest $request       The scope, the uuid, `scoped` and `preResolved`.
	 * @param bool            $_rbac         Whether to apply RBAC checks.
	 * @param bool            $_multitenancy Whether to apply multitenancy filtering.
	 *
	 * @return array{object: ObjectEntity, register: Register|int|string, schema: Schema|int|string}
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If `scoped` is set and the UUID is
	 *                                                    not present in the given magic table.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveDeletionContext(DeletionRequest $request, bool $_rbac, bool $_multitenancy): array {
		$given = self::givenContext(request: $request);
		if ($given !== null) {
			return $given;
		}

		// Scoped path: lookup hits exactly one magic table — a UUID in a
		// different scope raises DoesNotExistException. This is the fix for
		// #1638 (cross-scope silent deletes).
		$table = self::concreteTable(request: $request);
		if ($request->options->scoped === true && $table !== null) {
			$scopedObject = $this->objectEntityMapper->find(
				identifier: $request->uuid,
				register: $table->register,
				schema: $table->schema,
				includeDeleted: true,
				_rbac: $_rbac,
				_multitenancy: $_multitenancy
			);

			return ['object' => $scopedObject, 'register' => $table->register, 'schema' => $table->schema];
		}

		// Legacy path: cross-table scan via findAcrossAllSources — still in
		// use by every unscoped caller. Soft-deprecated; preferred call site
		// is the scoped branch above.
		return $this->objectEntityMapper->findAcrossAllSources(
			identifier: $request->uuid,
			includeDeleted: true,
			_rbac: $_rbac,
			_multitenancy: $_multitenancy
		);
	}//end resolveDeletionContext()

	/**
	 * The context of a caller that already resolved the entity, or null.
	 *
	 * PERF: bulk delete performs ONE batched cross-table lookup for all UUIDs
	 * and passes each entity in, so the per-object magic-table scan is skipped.
	 * Defensive: the entity must match the requested uuid and the scope must be
	 * concrete entities.
	 *
	 * @param DeletionRequest $request The scope, the uuid and the pre-resolved entity.
	 *
	 * @return array{object: ObjectEntity, register: Register, schema: Schema}|null The context, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function givenContext(DeletionRequest $request): ?array {
		$preResolved = $request->options->preResolved;
		$table       = self::concreteTable(request: $request);
		if ($preResolved === null || $table === null || $preResolved->getUuid() !== $request->uuid) {
			return null;
		}

		return ['object' => $preResolved, 'register' => $table->register, 'schema' => $table->schema];
	}//end givenContext()

	/**
	 * The request's register and schema when both are concrete entities, otherwise null.
	 *
	 * @param DeletionRequest $request The deletion request.
	 *
	 * @return RegisterSchema|null The table.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function concreteTable(DeletionRequest $request): ?RegisterSchema {
		if ($request->register instanceof Register && $request->schema instanceof Schema) {
			return new RegisterSchema(register: $request->register, schema: $request->schema);
		}

		return null;
	}//end concreteTable()

	/**
	 * Handle referential integrity checks and cascade deletion for root deletions.
	 *
	 * Returns the deletion result if integrity processing handled the delete,
	 * or null if no integrity actions were needed (caller should do simple delete).
	 *
	 * @param DeletionRun $run The resolved context and the uuid asked for.
	 *
	 * @return bool|null The result, or null if no integrity processing was needed
	 *
	 * @throws ReferentialIntegrityException If blocked by RESTRICT
	 * @throws Exception If cascade transaction fails
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function handleIntegrityDeletion(DeletionRun $run): ?bool {
		$object = $run->object();

		// The root's guards answer before any child is cascaded away.
		$this->objectEntityMapper->assertDeletable(entity: $object);
		$this->guardsAsked[(string)$object->getUuid()] = true;

		$schemaId = $object->getSchema();
		$hasIntegrityAction = $schemaId !== null
			&& $this->integrityService->hasIncomingOnDeleteReferences($schemaId) === true;

		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.relindex');

		// Run legacy cascade regardless of integrity actions.
		if ($hasIntegrityAction === false) {
			$this->runLegacyCascade(context: $run->context, object: $object, uuid: $run->uuid);
			\OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.legacycascade');
			return null;
		}

		$analysis = $this->integrityService->canDelete($object);
		if ($analysis->deletable === false) {
			$this->logAndThrowRestrict(run: $run, analysis: $analysis);
		}

		return $this->executeIntegrityTransaction(run: $run, analysis: $analysis);
	}//end handleIntegrityDeletion()

	/**
	 * Log a RESTRICT block and throw the exception.
	 *
	 * @param DeletionRun      $run      The resolved context and the uuid asked for.
	 * @param DeletionAnalysis $analysis The analysis result
	 *
	 * @return void
	 *
	 * @throws ReferentialIntegrityException Always thrown
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function logAndThrowRestrict(DeletionRun $run, DeletionAnalysis $analysis): void {
		[$userId] = $this->resolveUserContext();

		$this->integrityService->logRestrictBlock(
			objectUuid: $run->uuid,
			schemaId: $run->object()->getSchema(),
			analysis: $analysis,
			userId: $userId
		);

		throw new ReferentialIntegrityException(analysis: $analysis);
	}//end logAndThrowRestrict()

	/**
	 * Execute integrity cascade actions and root deletion within a transaction.
	 *
	 * @param DeletionRun      $run      The resolved context and the uuid asked for.
	 * @param DeletionAnalysis $analysis The deletion analysis
	 *
	 * @return bool True if deletion succeeded
	 *
	 * @throws Exception If the transaction fails
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function executeIntegrityTransaction(DeletionRun $run, DeletionAnalysis $analysis): bool {
		$this->db->beginTransaction();
		try {
			[$userId, $activeOrg] = $this->resolveUserContext();

			$integrityResult = $this->integrityService->applyDeletionActions(
				$analysis,
				$userId,
				$run->uuid,
				$activeOrg,
				$run->triggerSlug()
			);

			// A RETAINED RECORD STAYS LIVE EVEN WHEN ITS PARENT GOES, so it is not
			// a cascade casualty and must not be counted as one. The count used to
			// be `count($analysis->cascadeTargets)`, the number of rows the
			// analysis WANTED to delete, which is now a different number from the
			// rows the cascade actually took. Reporting the analysis figure would
			// tell a bulk caller that a record it can still see was deleted.
			$this->lastRetainedTargets = $integrityResult['retained'];

			$cCount = (count($analysis->cascadeTargets) - count($this->lastRetainedTargets));
			$nCount = count($analysis->nullifyTargets);
			$dCount = count($analysis->defaultTargets);
			$this->lastCascadeCount = (max(0, $cCount) + $nCount + $dCount);

			$this->runLegacyCascade(context: $run->context, object: $run->object(), uuid: $run->uuid);

			$rootCascadeCtx = self::buildCascadeContext(run: $run, analysis: $analysis);

			$result = $this->delete(
				object: $run->object(),
				options: new RemovalOptions(cascadeContext: $rootCascadeCtx, table: $run->table())
			);
			$this->db->commit();
			return $result;
		} catch (Exception $e) {
			$this->db->rollBack();
			$this->logger->error(
				message: '[DeleteObject] Transaction rolled back: cascade or delete failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $run->uuid,
					'error' => $e->getMessage(),
				]
			);
			throw $e;
		}//end try

	}//end executeIntegrityTransaction()

	/**
	 * Run legacy cascade: true deletion if register and schema are available.
	 *
	 * @param array $context The object context
	 * @param ObjectEntity $object The object being deleted
	 * @param string $uuid The object UUID
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function runLegacyCascade(array $context, ObjectEntity $object, string $uuid): void {
		$contextRegister = $context['register'] ?? null;
		$contextSchema = $context['schema'] ?? null;

		if ($contextRegister instanceof Register && $contextSchema instanceof Schema) {
			$this->cascadeDeleteObjects(
				root: new DeletionTarget(object: $object, register: $contextRegister, schema: $contextSchema),
				originalObjectId: $uuid
			);
		}

	}//end runLegacyCascade()

	/**
	 * Build cascade context metadata for audit trail tagging.
	 *
	 * @param DeletionRun      $run      The root's context (trigger schema) and uuid.
	 * @param DeletionAnalysis $analysis The deletion analysis
	 *
	 * @return array|null The cascade context, or null if no cascades occurred
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function buildCascadeContext(DeletionRun $run, DeletionAnalysis $analysis): ?array {
		$cascadeCount = count($analysis->cascadeTargets);
		$nullifyCount = count($analysis->nullifyTargets);
		$defaultCount = count($analysis->defaultTargets);

		if ($cascadeCount === 0 && $nullifyCount === 0 && $defaultCount === 0) {
			return null;
		}

		return [
			'action_type' => 'referential_integrity.root_delete',
			'triggerObject' => $run->uuid,
			'triggerSchema' => $run->triggerSlug(),
			'cascadeDeleteCount' => $cascadeCount,
			'setNullCount' => $nullifyCount,
			'setDefaultCount' => $defaultCount,
		];

	}//end buildCascadeContext()

	/**
	 * Resolve the current user ID and active organisation.
	 *
	 * @return array{0: string, 1: mixed} [userId, activeOrganisation]
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function resolveUserContext(): array {
		$user = $this->userSession->getUser();
		$userId = 'system';
		$org = null;

		if ($user !== null) {
			$userId = $user->getUID();
			try {
				$org = $this->organisationMapper->getActiveOrganisationWithFallback($user->getUID());
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
	 * @param DeletionTarget $root             The object being deleted with its register and schema.
	 * @param string         $originalObjectId The ID of original object for cascading.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function cascadeDeleteObjects(DeletionTarget $root, string $originalObjectId): void {
		$register = $root->register;
		$schema = $root->schema;
		if ($register === null || $schema === null) {
			return;
		}

		$cascadeIds = $this->collectCascadeTargetIds(schema: $schema, object: $root->object);
		if (empty($cascadeIds) === true) {
			return;
		}

		$handledIds = $this->softDeleteCascadeTargets(targets: $this->lookupCascadeTargets(cascadeIds: $cascadeIds));

		// Legacy per-id pipeline for ids the batch lookup did not resolve.
		foreach (self::unhandledIds(ids: $cascadeIds, handledIds: $handledIds) as $cascadeId) {
			$options = new DeletionOptions(originalObjectId: $originalObjectId);
			$this->deleteObject(request: new DeletionRequest(register: $register, schema: $schema, uuid: $cascadeId, options: $options));
		}
	}//end cascadeDeleteObjects()

	/**
	 * Resolve every level-one cascade target in one batched lookup.
	 *
	 * A failed lookup is logged and resolves nothing, so every id falls back to
	 * the per-id pipeline.
	 *
	 * @param array<int, string> $cascadeIds The referenced ids.
	 *
	 * @return ObjectEntity[] The resolved targets.
	 */
	private function lookupCascadeTargets(array $cascadeIds): array {
		try {
			return $this->objectEntityMapper->findStoredAcrossAllMagicTables(
				uuids: $cascadeIds,
				includeDeleted: true
			);
		} catch (\Throwable $e) {
			$this->logCascadeLookupFailure(error: $e);
		}

		return [];
	}//end lookupCascadeTargets()

	/**
	 * Log a batched cascade lookup that failed.
	 *
	 * @param \Throwable $error Why it failed.
	 *
	 * @return void
	 */
	private function logCascadeLookupFailure(\Throwable $error): void {
		$this->logger->warning(
			message: '[DeleteObject] Batched cascade lookup failed; falling back to per-id deletes',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'error' => $error->getMessage(),
			]
		);
	}//end logCascadeLookupFailure()

	/**
	 * Guard, then batch-soft-delete the resolved targets that are not deleted yet.
	 *
	 * @param ObjectEntity[] $targets The resolved cascade targets.
	 *
	 * @return array<string, true> Ids taken care of (batch-deleted or already deleted), keyed by uuid.
	 */
	private function softDeleteCascadeTargets(array $targets): array {
		$this->assertCascadeTargetsDeletable(targets: $targets);

		$alreadyDeleted = array_filter($targets, static fn (ObjectEntity $target): bool => $target->isSoftDeleted());
		$handledIds = $this->batchCascadeSoftDelete(targets: array_diff_key($targets, $alreadyDeleted));
		foreach ($alreadyDeleted as $target) {
			$handledIds[(string)$target->getUuid()] = true;
		}

		return $handledIds;
	}//end softDeleteCascadeTargets()

	/**
	 * Ask the delete guards about every live cascade target.
	 *
	 * The batched write dispatches update events only, so a guard on the deleting
	 * event answers here: one refused child refuses the whole cascade.
	 *
	 * @param ObjectEntity[] $targets The resolved cascade targets.
	 *
	 * @return void
	 */
	private function assertCascadeTargetsDeletable(array $targets): void {
		foreach ($targets as $target) {
			if (empty($target->getDeleted()) === true) {
				$this->objectEntityMapper->assertDeletable(entity: $target);
			}
		}
	}//end assertCascadeTargetsDeletable()

	/**
	 * The ids the batch did not take care of, in their original order.
	 *
	 * @param array<int, string>  $ids        The referenced ids.
	 * @param array<string, true> $handledIds The ids taken care of.
	 *
	 * @return array<int, string>
	 */
	private static function unhandledIds(array $ids, array $handledIds): array {
		$unhandled = [];
		foreach ($ids as $id) {
			if (isset($handledIds[$id]) === false) {
				$unhandled[] = $id;
			}
		}

		return $unhandled;
	}//end unhandledIds()

	/**
	 * Collect the referenced ids of all `cascade: true` schema properties.
	 *
	 * @param Schema $schema The schema whose properties are inspected.
	 * @param ObjectEntity $object The object holding the reference values.
	 *
	 * @return string[] Unique, non-empty referenced ids.
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function collectCascadeTargetIds(Schema $schema, ObjectEntity $object): array {
		$ids = [];
		$properties = $schema->getProperties();
		foreach ($properties ?? [] as $propertyName => $property) {
			if (self::isCascadeProperty(property: $property) === false) {
				continue;
			}

			$candidates = self::cascadeCandidatesOf(value: $object->getObject()[$propertyName] ?? null);
			array_push($ids, ...self::usableCascadeIds(candidates: $candidates));
		}

		return array_values(array_unique($ids));
	}//end collectCascadeTargetIds()

	/**
	 * Whether a schema property declares `cascade: true`.
	 *
	 * @param mixed $property The property definition.
	 *
	 * @return bool
	 */
	private static function isCascadeProperty(mixed $property): bool {
		return (isset($property['cascade']) === true && $property['cascade'] === true);
	}//end isCascadeProperty()

	/**
	 * The candidate references in one cascade property's value: none for null,
	 * the members of an array, or the single value.
	 *
	 * @param mixed $value The property value.
	 *
	 * @return array<mixed>
	 */
	private static function cascadeCandidatesOf(mixed $value): array {
		if ($value === null) {
			return [];
		}

		if (is_array($value) === true) {
			return $value;
		}

		return [$value];
	}//end cascadeCandidatesOf()

	/**
	 * The scalar, non-empty candidates as id strings, in order.
	 *
	 * @param array<mixed> $candidates The candidate references.
	 *
	 * @return array<int, string>
	 */
	private static function usableCascadeIds(array $candidates): array {
		$ids = [];
		foreach ($candidates as $candidate) {
			if (is_scalar($candidate) === false || (string)$candidate === '') {
				continue;
			}

			$ids[] = (string)$candidate;
		}

		return $ids;
	}//end usableCascadeIds()

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
	private function batchCascadeSoftDelete(array $targets): array {
		if (empty($targets) === true) {
			return [];
		}

		[$userId, $activeOrganisation] = $this->resolveUserContext();
		[$handled, $oldEntities] = (new SoftDeleteStamp(deletedBy: $userId, organisation: $activeOrganisation))->apply(targets: $targets);

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
					'file' => __FILE__,
					'line' => __LINE__,
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
	private function writeCascadeAuditTrails(array $deleted): void {
		if (empty($deleted) === true || $this->isAuditTrailsEnabled() === false) {
			return;
		}

		$rows = $this->buildCascadeAuditRows(deleted: $deleted);
		if (empty($rows) === true) {
			return;
		}

		$this->insertCascadeAuditRows(rows: $rows);
	}//end writeCascadeAuditTrails()

	/**
	 * Build one audit row per deleted entity; a row that cannot be built is logged and skipped.
	 *
	 * @param ObjectEntity[] $deleted The successfully soft-deleted entities.
	 *
	 * @return array<int, \OCA\OpenRegister\Db\AuditTrail> The rows, in entity order.
	 */
	private function buildCascadeAuditRows(array $deleted): array {
		$rows = [];
		foreach ($deleted as $entity) {
			try {
				$rows[] = $this->auditTrailMapper->buildEntry(change: new AuditedChange(old: $entity, new: null, action: 'delete'));
			} catch (\Throwable $e) {
				$this->logCascadeAuditRowFailure(entity: $entity, error: $e);
			}
		}

		return $rows;
	}//end buildCascadeAuditRows()

	/**
	 * Log a cascade audit row that could not be built.
	 *
	 * @param ObjectEntity $entity The entity the row was for.
	 * @param \Throwable   $error  Why it could not be built.
	 *
	 * @return void
	 */
	private function logCascadeAuditRowFailure(ObjectEntity $entity, \Throwable $error): void {
		$this->logger->warning(
			message: '[DeleteObject] Could not build cascade audit row',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $entity->getUuid(),
				'error' => $error->getMessage(),
			]
		);
	}//end logCascadeAuditRowFailure()

	/**
	 * Insert the cascade audit rows in one bulk INSERT; a failure is logged, never thrown.
	 *
	 * @param array<int, \OCA\OpenRegister\Db\AuditTrail> $rows The rows.
	 *
	 * @return void
	 */
	private function insertCascadeAuditRows(array $rows): void {
		try {
			$this->auditTrailMapper->insertAuditTrails(entries: $rows);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[DeleteObject] Bulk cascade audit insert failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'count' => count($rows),
					'error' => $e->getMessage(),
				]
			);
		}
	}//end insertCascadeAuditRows()

	/**
	 * Invalidate caches per soft-deleted object (parity with delete()).
	 *
	 * @param ObjectEntity[] $deleted The successfully soft-deleted entities.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function invalidateCachesForDeleted(array $deleted): void {
		foreach ($deleted as $entity) {
			$registerIdInt = null;
			if (is_numeric($entity->getRegister()) === true) {
				$registerIdInt = (int)$entity->getRegister();
			}

			$schemaIdInt = null;
			if (is_numeric($entity->getSchema()) === true) {
				$schemaIdInt = (int)$entity->getSchema();
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
				$this->logger->warning(
					message: '[DeleteObject] Cache invalidation failed after a soft delete',
					context: [
						'uuid' => $entity->getUuid(),
						'exception' => $e->getMessage(),
					]
				);
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
	private function isAuditTrailsEnabled(): bool {
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
	public function getLastCascadeCount(): int {
		return $this->lastCascadeCount;
	}//end getLastCascadeCount()

	/**
	 * Get the cascade targets the archival obligation kept live in the last root delete.
	 *
	 * The parent delete proceeded; these records did not go with it. Each entry
	 * names the record, the ground it was kept on and what the reader does next,
	 * so a caller can report the retention rather than leave it to a log line.
	 *
	 * @return array<int, array<string, mixed>> One report per retained record, empty when none.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function getLastRetainedCascadeTargets(): array {
		return $this->lastRetainedTargets;
	}//end getLastRetainedCascadeTargets()

	/**
	 * Delegate a delete on an object-source schema to its writable provider.
	 *
	 * Preserves the v1 read-only rejection unless the annotation carries
	 * `readOnly: false` AND a writable provider is registered AND the register
	 * resolves. External deletes are hard deletes; zero affected rows surfaces
	 * as the same DoesNotExistException (404) an absent native object produces.
	 *
	 * @param Schema          $schema  The sourced schema, carrying the `x-openregister-object-source` annotation.
	 * @param DeletionRequest $request The register (entity or identifier) and the object id (external key).
	 *
	 * @return bool True when the external row was deleted.
	 *
	 * @throws \RuntimeException When the schema is not writable (v1 rejection).
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no external row matches the id.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Fail-closed writable checks require explicit branching
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Fail-closed writable checks require explicit branching
	 */
	private function delegateObjectSourceDelete(Schema $schema, DeletionRequest $request): bool {
		$objectSource = $schema->getObjectSource();
		$uuid         = $request->uuid;
		$provider     = null;
		if ($this->objectSourceRegistry !== null) {
			$provider = $this->objectSourceRegistry->get((string)$objectSource['provider']);
		}

		$registerEntity = $this->objectSourceRegister(register: $request->register);

		$writableOptIn = (($objectSource['readOnly'] ?? true) === false);
		$writable = ($provider instanceof \OCA\OpenRegister\Service\ObjectSource\WritableObjectSourceProvider);

		if ($writableOptIn === false || $writable === false || $registerEntity === null) {
			throw new RuntimeException(
				sprintf(
					'Schema "%s" is a read-only projection of object-source provider "%s"; deletes are not allowed.',
					(string)$schema->getSlug(),
					$objectSource['provider']
				)
			);
		}

		$config = ($objectSource['config'] ?? []);

		// Pre-read for the audit record (best effort).
		$old = $provider->find(register: $registerEntity, schema: $schema, id: $uuid, config: $config);

		$deleted = $provider->remove(register: $registerEntity, schema: $schema, id: $uuid, config: $config);
		if ($deleted === false) {
			throw new DoesNotExistException('No external row matches id ' . $uuid);
		}

		if ($old !== null) {
			try {
				$this->auditTrailMapper->createAuditTrail(old: $old, new: null, action: 'delete');
			} catch (\Throwable $e) {
				$this->logger->warning(
					'[DeleteObject] audit trail for external delete on uuid ' . $uuid . ' could not be recorded: ' . $e->getMessage(),
					['file' => __FILE__, 'line' => __LINE__]
				);
			}
		}

		return true;
	}//end delegateObjectSourceDelete()

	/**
	 * The register of an object-source delete as an entity, or null when it does not resolve.
	 *
	 * @param Register|int|string|null $register The register (entity or identifier).
	 *
	 * @return Register|null The register entity.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function objectSourceRegister(Register|int|string|null $register): ?Register {
		if ($register instanceof Register) {
			return $register;
		}

		if ($register === null || $this->registerMapper === null) {
			return null;
		}

		try {
			return $this->registerMapper->find($register);
		} catch (\Throwable $e) {
			return null;
		}
	}//end objectSourceRegister()
}//end class
