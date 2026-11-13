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

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use Exception;
use JsonSerializable;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Dto\DeletionEffects;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCA\OpenRegister\Service\FileFolderServiceInterface;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
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
	 * Constructor for DeleteObject handler.
	 *
	 * @param MagicMapperInterface $objectEntityMapper Object entity data mapper.
	 * @param CacheHandlerInterface $cacheHandler Object entity and query cache
	 * @param AuditRecording $audit Audit trail mapper for logs, and the logger for error handling
	 * @param SettingsServiceInterface $settingsService Settings service for trail settings
	 * @param DeletionIntegrity $integrity The onDelete analysis, refusal and actions, in one transaction
	 * @param DeletionAttribution $attribution The deleting user and organisation, and the deleted metadata
	 * @param FileFolderServiceInterface|null $fileService File service for object folder cleanup
	 * @param \OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry|null $objectSourceRegistry Writable object-source provider registry
	 * @param \OCA\OpenRegister\Db\RegisterMapperInterface|null $registerMapper Register mapper for register lookups
	 * @param DeleteRowRules|null $rowRules Checks each `cascade: true` child; without it a root with children is refused
	 * @param IEventDispatcher|null $eventDispatcher Announces a permanent delete to the object-event listeners
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) DI constructor wires all delete-path collaborators
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectEntityMapper,
		private readonly CacheHandlerInterface $cacheHandler,
		AuditRecording $audit,
		SettingsServiceInterface $settingsService,
		private readonly DeletionIntegrity $integrity,
		private readonly DeletionAttribution $attribution,
		private readonly ?FileFolderServiceInterface $fileService = null,
		private readonly ?\OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry $objectSourceRegistry = null,
		private readonly ?\OCA\OpenRegister\Db\RegisterMapperInterface $registerMapper = null,
		private readonly ?DeleteRowRules $rowRules = null,
		private readonly ?IEventDispatcher $eventDispatcher = null,
	) {
		$this->auditTrailMapper = $audit->trail;
		$this->settingsService = $settingsService;
		$this->logger = $audit->logger;
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

		// The mapper's deleteObjectEntity() never dispatches ObjectDeletedEvent, so listeners such as an
		// external search-index sync only learn of a permanent delete from here.
		if ($this->eventDispatcher !== null) {
			(new ObjectEventGate(events: $this->eventDispatcher))->deleted(object: $objectEntity);
		}

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
		$deletionData = $this->attribution->deletedMetadata(target: $target);

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
		return $this->integrity->analyse(object: $object);
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

		$caller = new DeleteCaller(userId: $request->options->actingUserId, rbac: $_rbac, multitenancy: $_multitenancy, reach: $request->options->reach);

		return $this->deleteResolved(run: new DeletionRun(context: $context, uuid: $request->uuid, caller: $caller), options: $request->options);
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
			$integrityResult = $this->handleIntegrityDeletion(run: $run, permanent: $options->permanent);
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
	 * Check every cascade the root delete implies, then run them and the root delete together.
	 *
	 * Returns the deletion result when a cascade ran, or null when the root has neither
	 * onDelete dependents nor `cascade: true` children (the caller then does a simple delete).
	 * Every `cascade: true` child is checked before anything is written: one refused
	 * child refuses the root delete.
	 *
	 * @param DeletionRun $run       The resolved context, the uuid asked for and the caller.
	 * @param bool        $permanent Whether the root is destroyed instead of tombstoned.
	 *
	 * @return bool|null The result, or null if no cascade was needed
	 *
	 * @throws ReferentialIntegrityException If blocked by RESTRICT, or a child is outside its property's table
	 * @throws Exception If a child may not be deleted, or the cascade transaction fails
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function handleIntegrityDeletion(DeletionRun $run, bool $permanent): ?bool {
		$object = $run->object();

		// The root's guards answer before any child is cascaded away.
		$this->objectEntityMapper->assertDeletable(entity: $object);
		$this->guardsAsked[(string)$object->getUuid()] = true;

		$children = $this->permittedCascadeChildren(run: $run);

		$schemaId = $object->getSchema();
		$hasIntegrityAction = $this->integrity->governs(schemaId: $schemaId);

		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('del.relindex');

		if ($hasIntegrityAction === false && $children === []) {
			return null;
		}

		$analysis = DeletionAnalysis::empty();
		if ($hasIntegrityAction === true) {
			$analysis = $this->integrity->analyse(object: $object);
		}

		if ($analysis->deletable === false) {
			$this->logAndThrowRestrict(run: $run, analysis: $analysis);
		}

		$this->refuseUnreachedDependents(run: $run, analysis: $analysis, children: $children);

		return $this->executeIntegrityTransaction(run: $run, analysis: self::withChildren(analysis: $analysis, children: $children), children: $children, permanent: $permanent);
	}//end handleIntegrityDeletion()

	/**
	 * Log a RESTRICT block and throw the exception.
	 *
	 * @param DeletionRun      $run      The resolved context and the uuid asked for.
	 * @param DeletionAnalysis $analysis The analysis result
	 *
	 * @return never
	 *
	 * @throws ReferentialIntegrityException Always thrown
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function logAndThrowRestrict(DeletionRun $run, DeletionAnalysis $analysis): never {
		[$userId] = $this->attribution->actingUser();

		$this->integrity->refuse(
			refused: new RefusedDeletion(objectUuid: $run->uuid, schemaId: $run->object()->getSchema(), userId: $userId),
			analysis: $analysis
		);
	}//end logAndThrowRestrict()

	/**
	 * Run the cascade and the root deletion within one transaction.
	 *
	 * The `cascade: true` children are cascade targets of the same analysis as the onDelete
	 * dependents: the archival obligation keeps a retained child live, and each child's
	 * audit row names the root delete. A root delete that fails rolls every child back.
	 *
	 * @param DeletionRun                 $run       The resolved context and the uuid asked for.
	 * @param DeletionAnalysis            $analysis  The deletion analysis, with the children as cascade targets.
	 * @param array<string, CascadeChild> $children  The checked `cascade: true` children, keyed by uuid.
	 * @param bool                        $permanent Whether the root is destroyed instead of tombstoned.
	 *
	 * @return bool True if deletion succeeded
	 *
	 * @throws Exception If the transaction fails
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function executeIntegrityTransaction(DeletionRun $run, DeletionAnalysis $analysis, array $children, bool $permanent): bool {
		try {
			$result = $this->integrity->atomically(
				work: fn (): bool => $this->applyIntegrityAndDeleteRoot(run: $run, analysis: $analysis, permanent: $permanent)
			);
		} catch (Exception $e) {
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

		if ($result === false) {
			return false;
		}

		$this->invalidateCachesForDeleted(deleted: self::withoutRetained(children: $children, retained: $this->lastRetainedTargets));
		return true;
	}//end executeIntegrityTransaction()

	/**
	 * Apply the integrity actions and delete the root, counting the cascade.
	 *
	 * @param DeletionRun      $run       The resolved context and the uuid asked for.
	 * @param DeletionAnalysis $analysis  The deletion analysis, with the children as cascade targets.
	 * @param bool             $permanent Whether the root is destroyed instead of tombstoned.
	 *
	 * @return bool True if deletion succeeded
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function applyIntegrityAndDeleteRoot(DeletionRun $run, DeletionAnalysis $analysis, bool $permanent): bool {
		[$userId, $activeOrg] = $this->attribution->actingUser();

		$integrityResult = $this->integrity->apply(
			analysis: $analysis,
			origin: new CascadeDeleteOrigin(userId: $userId, cascadeSource: $run->uuid, triggerSchemaSlug: $run->triggerSlug(), organisationId: $activeOrg)
		);

		// A RETAINED RECORD STAYS LIVE EVEN WHEN ITS PARENT GOES, so it is not
		// a cascade casualty and must not be counted as one.
		$this->lastRetainedTargets = $integrityResult['retained'];

		$cCount = (count($analysis->cascadeTargets) - count($this->lastRetainedTargets));
		$nCount = count($analysis->nullifyTargets);
		$dCount = count($analysis->defaultTargets);
		$this->lastCascadeCount = (max(0, $cCount) + $nCount + $dCount);

		return $this->delete(
			object: $run->object(),
			options: new RemovalOptions(cascadeContext: self::buildCascadeContext(run: $run, analysis: $analysis), permanent: $permanent, table: $run->table())
		);
	}//end applyIntegrityAndDeleteRoot()

	/**
	 * The analysis with the `cascade: true` children added as cascade targets.
	 *
	 * A child that is an onDelete cascade target already is not added twice.
	 *
	 * @param DeletionAnalysis            $analysis The onDelete analysis.
	 * @param array<string, CascadeChild> $children The checked children, keyed by uuid.
	 *
	 * @return DeletionAnalysis
	 */
	private static function withChildren(DeletionAnalysis $analysis, array $children): DeletionAnalysis {
		$targets = $analysis->cascadeTargets;
		$known   = array_flip(array_map(static fn (array $target): string => (string)($target['objectUuid'] ?? ''), $targets));
		foreach ($children as $uuid => $child) {
			if (isset($known[$uuid]) === false) {
				$targets[] = $child->cascadeTarget();
			}
		}

		return new DeletionAnalysis(
			deletable: true,
			effects: new DeletionEffects(
				cascadeTargets: $targets,
				nullifyTargets: $analysis->nullifyTargets,
				defaultTargets: $analysis->defaultTargets
			),
			chainPaths: $analysis->chainPaths
		);
	}//end withChildren()

	/**
	 * The children the cascade deleted: all but the retained ones.
	 *
	 * @param array<string, CascadeChild>      $children The checked children, keyed by uuid.
	 * @param array<int, array<string, mixed>> $retained The retained reports.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private static function withoutRetained(array $children, array $retained): array {
		foreach ($retained as $report) {
			unset($children[(string)($report['uuid'] ?? '')]);
		}

		return array_values(array_map(static fn (CascadeChild $child): ObjectEntity => $child->entity, $children));
	}//end withoutRetained()

	/**
	 * Refuse the delete when the caller's reach does not cover every object it would change.
	 *
	 * Asked of each onDelete cascade, set-null and set-default target and each `cascade: true`
	 * child, before anything is written. The blocker names the property, not the object.
	 *
	 * @param DeletionRun                 $run      The root and the caller's reach.
	 * @param DeletionAnalysis            $analysis The onDelete analysis.
	 * @param array<string, CascadeChild> $children The checked `cascade: true` children, keyed by uuid.
	 *
	 * @return void
	 *
	 * @throws ReferentialIntegrityException When a dependent is outside the reach.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	private function refuseUnreachedDependents(DeletionRun $run, DeletionAnalysis $analysis, array $children): void {
		$reach = $run->caller->reach;
		if ($reach === null) {
			return;
		}

		$dependents = array_merge($analysis->cascadeTargets, $analysis->nullifyTargets, $analysis->defaultTargets);
		foreach ($children as $child) {
			$dependents[] = $child->cascadeTarget();
		}

		foreach ($dependents as $dependent) {
			if ($reach((string)($dependent['objectUuid'] ?? '')) !== true) {
				$blocked = self::blockedDependent(run: $run, propertyName: (string)($dependent['property'] ?? ''), reason: 'out_of_reach');
				$this->logAndThrowRestrict(run: $run, analysis: $blocked);
			}
		}
	}//end refuseUnreachedDependents()

	/**
	 * The live `cascade: true` children of the root, each checked the way a direct delete of it is.
	 *
	 * Each referenced id is looked up in the table its property names, with the caller's RBAC
	 * and organisation filters. An id that is not there refuses the root delete, so a
	 * reference cannot reach an object in another register, schema or organisation. A found
	 * child must pass the direct-delete refusals and the caller's delete right. Already
	 * deleted children are left out.
	 *
	 * @param DeletionRun $run The root, its table and the caller.
	 *
	 * @return array<string, CascadeChild> The children to delete, keyed by uuid.
	 *
	 * @throws ReferentialIntegrityException When a referenced id is outside its property's table.
	 * @throws Exception When a child may not be deleted.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function permittedCascadeChildren(DeletionRun $run): array {
		$table = $run->table();
		if ($table === null) {
			return [];
		}

		$children = [];
		foreach (($table->schema->getProperties() ?? []) as $propertyName => $property) {
			if (self::isCascadeProperty(property: $property) === false) {
				continue;
			}

			$ids = self::usableCascadeIds(candidates: self::cascadeCandidatesOf(value: $run->object()->getObject()[$propertyName] ?? null));
			if ($ids === []) {
				continue;
			}

			$childTable = $this->integrity->cascadePropertyTable(property: $property, owner: $table);
			foreach ($ids as $id) {
				$child = $this->permittedCascadeChild(run: $run, childTable: $childTable, id: $id, propertyName: (string)$propertyName);
				if ($child !== null) {
					$children[(string)$child->getUuid()] = new CascadeChild(entity: $child, property: (string)$propertyName);
				}
			}
		}

		return $children;
	}//end permittedCascadeChildren()

	/**
	 * One `cascade: true` child, found in its property's table and checked; null when already deleted.
	 *
	 * @param DeletionRun         $run          The root and the caller.
	 * @param RegisterSchema|null $childTable   The table the property names; null when it does not resolve.
	 * @param string              $id           The referenced id.
	 * @param string              $propertyName The cascade property.
	 *
	 * @return ObjectEntity|null The child, or null when it is deleted already.
	 *
	 * @throws ReferentialIntegrityException When the child is not in the table.
	 * @throws Exception When the child may not be deleted.
	 */
	private function permittedCascadeChild(DeletionRun $run, ?RegisterSchema $childTable, string $id, string $propertyName): ?ObjectEntity {
		$child = null;
		if ($childTable !== null && $this->rowRules !== null) {
			$child = $this->findCascadeChild(run: $run, childTable: $childTable, id: $id);
		}

		if ($child === null) {
			$this->logAndThrowRestrict(run: $run, analysis: self::blockedDependent(run: $run, propertyName: $propertyName));
		}

		if ($child->isSoftDeleted() === true) {
			return null;
		}

		$this->rowRules->assertDeletable(row: $child, table: $childTable, caller: $run->caller);
		return $child;
	}//end permittedCascadeChild()

	/**
	 * The child with this id in the table, as the caller may see it, or null.
	 *
	 * @param DeletionRun    $run        The caller.
	 * @param RegisterSchema $childTable The table the property names.
	 * @param string         $id         The referenced id.
	 *
	 * @return ObjectEntity|null
	 */
	private function findCascadeChild(DeletionRun $run, RegisterSchema $childTable, string $id): ?ObjectEntity {
		try {
			return $this->objectEntityMapper->find(
				identifier: $id,
				register: $childTable->register,
				schema: $childTable->schema,
				includeDeleted: true,
				_rbac: $run->caller->rbac,
				_multitenancy: $run->caller->multitenancy
			);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findCascadeChild()

	/**
	 * The analysis that refuses a root over one dependent: a cascade child outside its table, or one outside the reach.
	 *
	 * The blocker names the property, not the referenced id.
	 *
	 * @param DeletionRun $run          The root.
	 * @param string      $propertyName The cascade property.
	 * @param string      $reason       Why the dependent blocks.
	 *
	 * @return DeletionAnalysis
	 */
	private static function blockedDependent(DeletionRun $run, string $propertyName, string $reason='out_of_scope'): DeletionAnalysis {
		return new DeletionAnalysis(
			deletable: false,
			blockers: [[
				'objectUuid' => '',
				'schema' => $run->object()->getSchema(),
				'property' => $propertyName,
				'action' => 'CASCADE',
				'reason' => $reason,
				'chain' => [],
			]]
		);
	}//end blockedDependent()

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
		$objectSource   = $schema->getObjectSource();
		$provider       = $this->objectSourceProvider(objectSource: $objectSource);
		$registerEntity = $this->objectSourceRegister(register: $request->register);
		if (self::isWritableSource(objectSource: $objectSource, provider: $provider) === false || $registerEntity === null) {
			throw self::readOnlySourceRefusal(schema: $schema, objectSource: $objectSource);
		}

		$source = new SourcedTable(table: new RegisterSchema(register: $registerEntity, schema: $schema), config: ($objectSource['config'] ?? []));

		// Pre-read for the audit record (best effort).
		$old = $provider->find(source: $source, id: $request->uuid);
		if ($provider->remove(source: $source, id: $request->uuid) === false) {
			throw new DoesNotExistException('No external row matches id ' . $request->uuid);
		}

		$this->auditExternalDelete(old: $old, uuid: $request->uuid);
		return true;
	}//end delegateObjectSourceDelete()

	/**
	 * The provider an object-source block names, or null without a registry.
	 *
	 * @param array<string, mixed> $objectSource The schema's object-source block.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider|null The provider.
	 */
	private function objectSourceProvider(array $objectSource): ?\OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider {
		if ($this->objectSourceRegistry === null) {
			return null;
		}

		return $this->objectSourceRegistry->get((string)$objectSource['provider']);
	}//end objectSourceProvider()

	/**
	 * Whether the schema opted in to writes AND its provider can write.
	 *
	 * @param array<string, mixed> $objectSource The schema's object-source block.
	 * @param mixed                $provider     The provider, or null.
	 *
	 * @return bool
	 *
	 * @phpstan-assert-if-true \OCA\OpenRegister\Service\ObjectSource\WritableObjectSourceProvider $provider
	 */
	private static function isWritableSource(array $objectSource, mixed $provider): bool {
		$writableOptIn = (($objectSource['readOnly'] ?? true) === false);
		$writable = ($provider instanceof \OCA\OpenRegister\Service\ObjectSource\WritableObjectSourceProvider);

		return $writableOptIn === true && $writable === true;
	}//end isWritableSource()

	/**
	 * The refusal of a delete on a source that is (for this delete) a read-only projection.
	 *
	 * @param Schema               $schema       The schema.
	 * @param array<string, mixed> $objectSource The schema's object-source block.
	 *
	 * @return RuntimeException The refusal.
	 */
	private static function readOnlySourceRefusal(Schema $schema, array $objectSource): RuntimeException {
		return new RuntimeException(
			sprintf(
				'Schema "%s" is a read-only projection of object-source provider "%s"; deletes are not allowed.',
				(string)$schema->getSlug(),
				$objectSource['provider']
			)
		);
	}//end readOnlySourceRefusal()

	/**
	 * Record the audit row of an external delete, best effort: a failure is logged, never thrown.
	 *
	 * @param ObjectEntity|null $old  The row as it was, or null when the pre-read found none.
	 * @param string            $uuid The id deleted.
	 *
	 * @return void
	 */
	private function auditExternalDelete(?ObjectEntity $old, string $uuid): void {
		if ($old === null) {
			return;
		}

		try {
			$this->auditTrailMapper->createAuditTrail(old: $old, new: null, action: 'delete');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[DeleteObject] audit trail for external delete on uuid ' . $uuid . ' could not be recorded: ' . $e->getMessage(),
				['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end auditExternalDelete()

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

		return self::findRegister(registers: $this->registerMapper, register: $register);
	}//end objectSourceRegister()

	/**
	 * Look a register up by id; one that cannot be found resolves to null.
	 *
	 * @param \OCA\OpenRegister\Db\RegisterMapperInterface $registers The register mapper.
	 * @param int|string                                    $register  The register id.
	 *
	 * @return Register|null The register entity.
	 */
	private static function findRegister(\OCA\OpenRegister\Db\RegisterMapperInterface $registers, int|string $register): ?Register {
		try {
			return $registers->find($register);
		} catch (\Throwable $e) {
			return null;
		}
	}//end findRegister()
}//end class
