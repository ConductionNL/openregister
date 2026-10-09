<?php

/**
 * Whether a person may read or change the files of one object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ObjectFileAccessDeniedException;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WriteCause;
use Throwable;

/**
 * The one guard every file action on an object goes through.
 *
 * An object's files live in the `openregister` account's home, so Nextcloud's
 * own mounts no longer stop anybody: the object's rule is the only gate.
 * Reading a file asks the object read rule, through the same
 * `ObjectService::find(_rbac: true)` an object read uses, so groups,
 * `user:<uid>`, the owner rule, conditions and organisation scope all apply.
 * Changing a file asks `PermissionHandler` for `update` on the object.
 *
 * Every unknown answers no. An object that cannot be found, a rule that
 * cannot be read and a lookup that throws all refuse.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
 */
class ObjectFileAccess {

	/**
	 * Constructor.
	 *
	 * @param ObjectService     $objectService     Reads objects under the caller's RBAC.
	 * @param PermissionHandler $permissionHandler Decides the update rule on one object.
	 * @param SchemaMapper      $schemaMapper      Resolves the object's schema, which carries the rule.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly PermissionHandler $permissionHandler,
		private readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()

	/**
	 * The object behind a route, when the caller may read it.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object uuid or id.
	 *
	 * @return ObjectEntity The readable object.
	 *
	 * @throws ObjectFileAccessDeniedException With 404 when the caller may not read it.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
	 */
	public function readable(string $register, string $schema, string $id): ObjectEntity {
		try {
			$object = WriteCause::asLookup(fn () => $this->objectService->find(
				id: $id,
				register: $register,
				schema: $schema,
				_rbac: true,
				_multitenancy: true
			));
		} catch (Throwable $e) {
			throw new ObjectFileAccessDeniedException(httpStatus: ObjectFileAccessDeniedException::NOT_READABLE, previous: $e);
		}

		if ($object instanceof ObjectEntity === false) {
			throw new ObjectFileAccessDeniedException(httpStatus: ObjectFileAccessDeniedException::NOT_READABLE);
		}

		return $object;
	}//end readable()

	/**
	 * The object behind a route, when the caller may change it.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object uuid or id.
	 *
	 * @return ObjectEntity The changeable object.
	 *
	 * @throws ObjectFileAccessDeniedException With 404 when the caller may not read it,
	 *                                         403 when the caller may read but not update it.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-changing-an-objects-files-follows-the-objects-update-rule-req-ofoa-003
	 */
	public function changeable(string $register, string $schema, string $id): ObjectEntity {
		$object = $this->readable(register: $register, schema: $schema, id: $id);

		if ($this->mayUpdate(object: $object) === false) {
			throw new ObjectFileAccessDeniedException(httpStatus: ObjectFileAccessDeniedException::NOT_CHANGEABLE);
		}

		return $object;
	}//end changeable()

	/**
	 * Whether the caller may read an object already in hand.
	 *
	 * Used where a request names a file, not an object: download by file id and
	 * file search. The object is read again under the caller's RBAC, so the
	 * answer is the one an object read would give.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True when the caller may read it.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
	 */
	public function mayRead(ObjectEntity $object): bool {
		$identifier = ($object->getUuid() ?? (string)$object->getId());
		if ($identifier === '') {
			return false;
		}

		try {
			$readable = WriteCause::asLookup(fn () => $this->objectService->find(
				id: $identifier,
				register: $object->getRegister(),
				schema: $object->getSchema(),
				_rbac: true,
				_multitenancy: true
			));
		} catch (Throwable $e) {
			return false;
		}

		return $readable instanceof ObjectEntity;
	}//end mayRead()

	/**
	 * Whether the caller may update an object.
	 *
	 * @param ObjectEntity $object The object, already known to be readable.
	 *
	 * @return bool True when the update rule lets the caller change it.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-changing-an-objects-files-follows-the-objects-update-rule-req-ofoa-003
	 */
	public function mayUpdate(ObjectEntity $object): bool {
		$schemaId = $object->getSchema();
		if ($schemaId === null || $schemaId === '') {
			return false;
		}

		try {
			$schema = $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);

			return $this->permissionHandler->hasPermission(
				schema: $schema,
				action: 'update',
				userId: null,
				objectOwner: $object->getOwner(),
				_rbac: true,
				object: $object
			);
		} catch (Throwable $e) {
			return false;
		}
	}//end mayUpdate()
}//end class
