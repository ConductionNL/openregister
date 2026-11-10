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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\SettingsServiceInterface;
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
class RevertHandler implements RevertHandlerInterface {

	/**
	 * RevertHandler constructor.
	 *
	 * @param RevertStores $stores The audit trail, the object mapper and the save-path validator.
	 * @param ContainerInterface $container DI container.
	 * @param IEventDispatcher $eventDispatcher Event dispatcher.
	 * @param PermissionCheckInterface $permissionHandler Permission handler for RBAC.
	 */
	public function __construct(
		private readonly RevertStores $stores,
		private readonly ContainerInterface $container,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly PermissionCheckInterface $permissionHandler,
	) {
	}//end __construct()

	/**
	 * Revert an object to a previous state
	 *
	 * @param string $register The register identifier
	 * @param string $schema The schema identifier
	 * @param string $id The object ID
	 * @param RevertPoint $point The point to revert to, and whether to overwrite the version
	 *
	 * @return ObjectEntity The reverted object
	 *
	 * @throws DoesNotExistException If object not found
	 * @throws NotAuthorizedException If user not authorized
	 * @throws LockedException If object is locked
	 * @throws ObjectStateWriteException If the object is archived or frozen
	 * @throws ValidationException If the restored data fails the current schema
	 * @throws \Exception If reversion fails
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function revert(string $register, string $schema, string $id, RevertPoint $point): ObjectEntity {
		return $this->revertAt(ref: new ObjectRef(register: $register, schema: $schema, id: $id), point: $point);
	}//end revert()

	/**
	 * Revert the object an address names to a previous state.
	 *
	 * @param ObjectRef   $ref   The register, schema and object id as the route names them.
	 * @param RevertPoint $point The point to revert to, and whether to overwrite the version.
	 *
	 * @return ObjectEntity The reverted object
	 *
	 * @throws DoesNotExistException If object not found, or not under the named register/schema
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function revertAt(ObjectRef $ref, RevertPoint $point): ObjectEntity {
		// Get the object with RBAC and multitenancy enforced (tenant-scoped find).
		$context = $this->stores->objectEntityMapper->findAcrossAllSources(identifier: $ref->id, includeDeleted: false);
		$this->assertAddressed(ref: $ref, context: $context);
		$this->assertMayRevert(context: $context);

		// Revert from the object just checked: looked up again, the URL id could name another row.
		$revertedObject = $this->stores->auditTrailMapper->revertObject(
			identifier: $context['object'],
			until: $point->until,
			overwriteVersion: $point->overwriteVersion
		);
		$savedObject = $this->saveReverted(reverted: $revertedObject, context: $context);

		$this->eventDispatcher->dispatchTyped(new ObjectRevertedEvent(object: $savedObject, until: $point->until));

		return $savedObject;
	}//end revertAt()

	/**
	 * Answer not found unless the address names the register and schema the object is stored under.
	 *
	 * The route may name register and schema by id, UUID or slug, as every
	 * other object route allows (#4161).
	 *
	 * @param ObjectRef $ref     The register and schema as the route names them.
	 * @param array     $context The object, register and schema from the lookup.
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException If the address names another register or schema.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function assertAddressed(ObjectRef $ref, array $context): void {
		$registerNames = $this->namesOf(stored: (string) $context['object']->getRegister(), entity: $context['register']);
		$schemaNames = $this->namesOf(stored: (string) $context['object']->getSchema(), entity: $context['schema']);
		if (in_array((string) $ref->register, $registerNames, true) === false
			|| in_array((string) $ref->schema, $schemaNames, true) === false
		) {
			throw new DoesNotExistException('Object not found in specified register/schema');
		}
	}//end assertAddressed()

	/**
	 * Refuse a revert the caller may not make, in this order: no update
	 * permission on the object, a lock held by someone else, an archived or
	 * frozen object.
	 *
	 * Lock ownership is decided by the one production predicate, so a
	 * run-held lock refuses the run's own runAs user here as at every other
	 * guard. The archive and the freeze refuse a revert as SaveObject refuses
	 * every other write to such an object (#4105).
	 *
	 * @param array $context The object, register and schema from the lookup
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException If the caller may not update the object
	 * @throws LockedException If someone else holds the object's lock
	 * @throws ObjectStateWriteException If the object is archived or frozen
	 */
	private function assertMayRevert(array $context): void {
		$object = $context['object'];
		if ($this->permissionHandler->hasPermission(
			ask: (new PermissionAsk(schema: $context['schema'], action: 'update'))
				->about(object: $object, register: null)
		) === false) {
			throw new NotAuthorizedException(message: 'You do not have permission to revert this object');
		}

		if ($object->isLockedBySomeoneElse(userId: $this->container->get('userId'), runUuid: $this->callerRunUuid()) === true) {
			throw new LockedException(message: sprintf('Object is locked by %s', (string)$object->describeLockHolder()));
		}

		(new FileWriteGuard())->assertWritable(object: $object);
	}//end assertMayRevert()

	/**
	 * Validate the restored state against the current schema, write it, and
	 * record a `revert` audit row (the mapper writes none) when audit trails are on.
	 *
	 * Old data can come back in a shape the schema no longer allows, so it
	 * meets the current schema before it is written, as a save does.
	 *
	 * @param ObjectEntity $reverted The restored state
	 * @param array        $context  The object, register and schema from the lookup
	 *
	 * @return ObjectEntity The saved object
	 */
	private function saveReverted(ObjectEntity $reverted, array $context): ObjectEntity {
		$this->validateAgainstCurrentSchema(object: $reverted, schema: $context['schema']);
		$savedObject = $this->stores->objectEntityMapper->update(entity: $reverted, register: $context['register'], schema: $context['schema']);

		if ($this->isAuditTrailsEnabled() === true) {
			$this->stores->auditTrailMapper->createAuditTrail(old: $context['object'], new: $savedObject, action: 'revert');
		}

		return $savedObject;
	}//end saveReverted()

	/**
	 * The names a route may use for a stored register or schema: its stored id,
	 * and its UUID and slug when the looked-up entity is that stored one.
	 *
	 * An empty UUID or slug is no name, so an empty route segment only matches
	 * an empty stored id.
	 *
	 * @param string               $stored The id the object is stored under.
	 * @param Register|Schema|null $entity The register or schema the lookup returned.
	 *
	 * @return string[] The accepted names.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function namesOf(string $stored, Register|Schema|null $entity): array {
		if ($entity === null || (string) $entity->getId() !== $stored) {
			return [$stored];
		}

		$aliases = [(string) $entity->getUuid(), (string) $entity->getSlug()];

		return array_merge([$stored], array_filter($aliases, fn (string $name): bool => $name !== ''));
	}//end namesOf()

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
		$result = $this->stores->validateHandler->validateObject(
			object: $notSupplied->stripForValidation(object: $data),
			schema: $schema,
			notSupplied: $notSupplied->declared(object: $data)
		);

		if ($result->isValid() === false) {
			throw new ValidationException(
				message: $this->stores->validateHandler->generateErrorMessage(result: $result),
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
			$settings = $this->container->get(SettingsServiceInterface::class);
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
