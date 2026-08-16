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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;

/**
 * Class RevertHandler
 * Service for handling object reversion
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
	 * RevertHandler constructor.
	 *
	 * @param AuditTrailMapper $auditTrailMapper Audit trail mapper.
	 * @param ContainerInterface $container DI container.
	 * @param IEventDispatcher $eventDispatcher Event dispatcher.
	 * @param MagicMapper $objectEntityMapper Object entity mapper.
	 * @param PermissionHandler $permissionHandler Permission handler for RBAC.
	 */
	public function __construct(
		AuditTrailMapper $auditTrailMapper,
		ContainerInterface $container,
		IEventDispatcher $eventDispatcher,
		MagicMapper $objectEntityMapper,
		PermissionHandler $permissionHandler,
	) {
		$this->auditTrailMapper = $auditTrailMapper;
		$this->container = $container;
		$this->eventDispatcher = $eventDispatcher;
		$this->objectEntityMapper = $objectEntityMapper;
		$this->permissionHandler = $permissionHandler;
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
	 * @throws \Exception If reversion fails
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean needed to control version overwrite behavior
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
		if ($object->getRegister() !== $register || $object->getSchema() !== $schema) {
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

		// Check if the object is locked.
		if ($object->isLocked() === true) {
			$userId = $this->container->get('userId');
			if ($object->getLockedBy() !== $userId) {
				throw new LockedException(
					message: sprintf('Object is locked by %s', $object->getLockedBy())
				);
			}
		}

		// Get the reverted object using AuditTrailMapper.
		$revertedObject = $this->auditTrailMapper->revertObject(
			identifier: $id,
			until: $until,
			overwriteVersion: $overwriteVersion
		);

		// Save the reverted object (with register/schema context for magic mapper routing).
		$savedObject = $this->objectEntityMapper->update(
			entity: $revertedObject,
			register: $registerEntity,
			schema: $schemaEntity
		);

		// Dispatch revert event.
		$this->eventDispatcher->dispatchTyped(new ObjectRevertedEvent(object: $savedObject, until: $until));

		return $savedObject;
	}//end revert()
}//end class
