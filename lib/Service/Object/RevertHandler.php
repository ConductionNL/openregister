<?php

/**
 * OpenRegister RevertHandler
 *
 * Service class for handling object reversion in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;

/**
 * Class RevertHandler
 * Service for handling object reversion
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One collaborator over the
 * threshold, and it is the ambient flow-run stack: a revert is a write, and a
 * write guard that cannot name the caller's run refuses the run holding the
 * lock. Splitting the class to avoid naming one more type would trade a real
 * guard for a metric.
 */
class RevertHandler {

	/**
	 * Audit trail mapper
	 *
	 * @var AuditTrailMapper
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * Container
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * Event dispatcher
	 *
	 * @var IEventDispatcher
	 */
	private IEventDispatcher $eventDispatcher;

	/**
	 * Object entity mapper
	 *
	 * @var MagicMapper
	 */
	private MagicMapper $objectEntityMapper;

	/**
	 * Permission handler for RBAC enforcement
	 *
	 * @var PermissionHandler
	 */
	private PermissionHandler $permissionHandler;

	/**
	 * Validator the save path uses, so a revert meets the same schema.
	 *
	 * @var ValidateObject
	 */
	private ValidateObject $validateHandler;

	/**
	 * RevertHandler constructor.
	 *
	 * @param AuditTrailMapper $auditTrailMapper Audit trail mapper.
	 * @param ContainerInterface $container DI container.
	 * @param IEventDispatcher $eventDispatcher Event dispatcher.
	 * @param MagicMapper $objectEntityMapper Object entity mapper.
	 * @param PermissionHandler $permissionHandler Permission handler for RBAC.
	 * @param ValidateObject $validateHandler Schema validator of the save path.
	 */
	public function __construct(
		AuditTrailMapper $auditTrailMapper,
		ContainerInterface $container,
		IEventDispatcher $eventDispatcher,
		MagicMapper $objectEntityMapper,
		PermissionHandler $permissionHandler,
		ValidateObject $validateHandler,
	) {
		$this->auditTrailMapper = $auditTrailMapper;
		$this->container = $container;
		$this->eventDispatcher = $eventDispatcher;
		$this->objectEntityMapper = $objectEntityMapper;
		$this->permissionHandler = $permissionHandler;
		$this->validateHandler = $validateHandler;
	}//end __construct()

	/**
	 * Revert an object to a previous state
	 *
	 * @param string $register The register identifier
	 * @param string $schema The schema identifier
	 * @param string $id The object ID
	 * @param mixed $until The point to revert to (DateTime|string)
	 * @param bool $overwriteVersion Whether to overwrite the version
	 *
	 * @return ObjectEntity The reverted object
	 *
	 * @throws DoesNotExistException If object not found
	 * @throws NotAuthorizedException If user not authorized
	 * @throws LockedException If object is locked
	 * @throws ObjectStateWriteException If the object is frozen
	 * @throws ValidationException If the restored data fails the current schema
	 * @throws \Exception If reversion fails
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean needed to control version overwrite behavior
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectStateWriteException::frozen() is the one named constructor every write guard refuses with
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function revert(
		string $register,
		string $schema,
		string $id,
		mixed $until,
		bool $overwriteVersion = false,
	): ObjectEntity {
		// Get the object with RBAC and multitenancy enforced (tenant-scoped find).
		$context = $this->objectEntityMapper->findAcrossAllSources(
			identifier: $id,
			includeDeleted: false
		);
		$object = $context['object'];
		$registerEntity = $context['register'];
		$schemaEntity = $context['schema'];

		// Verify that the object belongs to the specified register and schema.
		// The route may name them by id, UUID or slug, as every other object
		// route allows; a string compare with the numeric id refused every slug (#4161).
		if ($this->namesEntity(given: $register, stored: (string) $object->getRegister(), entity: $registerEntity) === false
			|| $this->namesEntity(given: $schema, stored: (string) $object->getSchema(), entity: $schemaEntity) === false
		) {
			throw new DoesNotExistException('Object not found in specified register/schema');
		}

		// Enforce RBAC: the caller must have 'update' permission on this object.
		if ($this->permissionHandler->hasPermission(
			schema: $schemaEntity,
			action: 'update',
			object: $object
		) === false
		) {
			throw new NotAuthorizedException(
				message: 'You do not have permission to revert this object'
			);
		}

		// Check if the object is locked. Ownership is decided by the one
		// production predicate, so a run-held lock refuses the run's own
		// runAs user here exactly as it does at every other guard.
		if ($object->isLockedBySomeoneElse(userId: $this->container->get('userId'), runUuid: $this->callerRunUuid()) === true) {
			throw new LockedException(
				message: sprintf('Object is locked by %s', (string)$object->describeLockHolder())
			);
		}

		// A revert is a write, so the permanent freeze refuses it exactly as
		// SaveObject refuses every other write to a frozen object (#4105).
		if ($object->isFrozen() === true) {
			throw ObjectStateWriteException::frozen($object);
		}

		// Get the reverted object using AuditTrailMapper.
		$revertedObject = $this->auditTrailMapper->revertObject(
			identifier: $id,
			until: $until,
			overwriteVersion: $overwriteVersion
		);

		// Old data can come back in a shape the schema no longer allows, so it
		// meets the current schema before it is written, as a save does.
		$this->validateAgainstCurrentSchema(object: $revertedObject, schema: $schemaEntity);

		// Save the reverted object (with register/schema context for magic mapper routing).
		$savedObject = $this->objectEntityMapper->update(
			entity: $revertedObject,
			register: $registerEntity,
			schema: $schemaEntity
		);

		// The mapper writes no audit row, so the revert records its own: who
		// rolled back what is exactly what the audit trail is for.
		if ($this->isAuditTrailsEnabled() === true) {
			$this->auditTrailMapper->createAuditTrail(old: $object, new: $savedObject, action: 'revert');
		}

		// Dispatch revert event.
		$this->eventDispatcher->dispatchTyped(new ObjectRevertedEvent(object: $savedObject, until: $until));

		return $savedObject;
	}//end revert()

	/**
	 * Whether a route segment names the object's register or schema
	 *
	 * @param string      $given  The route segment: an id, a UUID or a slug.
	 * @param string      $stored The id the object stores.
	 * @param Register|Schema|null $entity The resolved register or schema, when known.
	 *
	 * @return bool True when the segment names the stored entity.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function namesEntity(string $given, string $stored, Register|Schema|null $entity): bool {
		if ($given === $stored) {
			return true;
		}

		if ($entity === null || (string) $entity->getId() !== $stored) {
			return false;
		}

		return in_array($given, [(string) $entity->getUuid(), (string) $entity->getSlug()], true) === true && $given !== '';
	}//end namesEntity()

	/**
	 * Validate restored data against the schema as it is now.
	 *
	 * Mirrors the save path: validation runs only when the schema has hard
	 * validation switched on, and a property recorded as not supplied is
	 * excused from the required rule.
	 *
	 * @param ObjectEntity $object The object carrying the restored data.
	 * @param Schema       $schema The object's current schema.
	 *
	 * @return void
	 *
	 * @throws ValidationException If the restored data fails the schema.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function validateAgainstCurrentSchema(ObjectEntity $object, Schema $schema): void {
		if ($schema->getHardValidation() !== true) {
			return;
		}

		$notSupplied = new NotSuppliedHandler();
		$data = ($object->getObject() ?? []);
		$result = $this->validateHandler->validateObject(
			object: $notSupplied->stripForValidation(object: $data),
			schema: $schema,
			notSupplied: $notSupplied->declared(object: $data)
		);

		if ($result->isValid() === false) {
			throw new ValidationException(
				message: $this->validateHandler->generateErrorMessage(result: $result),
				errors: $result->error()
			);
		}
	}//end validateAgainstCurrentSchema()

	/**
	 * Whether audit trails are switched on, read as the save path reads it.
	 *
	 * Resolved from the container because SettingsService is a wide service
	 * this handler needs for one flag. Anything that goes wrong answers true:
	 * an extra audit row is the safe direction, a missing one is the defect.
	 *
	 * @return bool True when a revert must be recorded.
	 */
	private function isAuditTrailsEnabled(): bool {
		try {
			$settings = $this->container->get(SettingsService::class);
			if (($settings instanceof SettingsService) === false) {
				return true;
			}

			return ($settings->getRetentionSettingsOnly()['auditTrailsEnabled'] ?? true) !== false;
		} catch (\Throwable $unavailable) {
			return true;
		}
	}//end isAuditTrailsEnabled()

	/**
	 * The flow run this write is being made for, or null when a person is
	 * writing.
	 *
	 * A run-scoped lock refuses every caller but the holding run, so a guard
	 * that cannot name the caller's run refuses the run that took the lock.
	 * Resolved from the container rather than injected because the ambient
	 * stack is a shared service and this is the only thing here that needs it.
	 *
	 * A container that cannot serve it answers "a person", which is the
	 * FAIL-CLOSED direction: a run lock refuses a caller with no run uuid, so
	 * the worst outcome is a refusal, never a lock walked through.
	 *
	 * @return string|null The executing run's uuid, or null.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-ownership-is-decided-by-one-predicate
	 */
	private function callerRunUuid(): ?string {
		try {
			$flowContext = $this->container->get(\OCA\OpenRegister\Service\Flow\FlowRunContext::class);
		} catch (\Throwable $unavailable) {
			return null;
		}

		if ($flowContext instanceof \OCA\OpenRegister\Service\Flow\FlowRunContext === false) {
			return null;
		}

		return $flowContext->currentRunUuid();
	}//end callerRunUuid()
}//end class
