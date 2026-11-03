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
use OCA\OpenRegister\Db\Schema;
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
	 * The file verb for reading an object's files.
	 *
	 * @var string
	 */
	public const READ_FILES = 'readFiles';

	/**
	 * The file verb for adding a file or changing one.
	 *
	 * @var string
	 */
	public const ADD_FILES = 'addFiles';

	/**
	 * The file verb for deleting a file.
	 *
	 * @var string
	 */
	public const DELETE_FILES = 'deleteFiles';

	/**
	 * The file verbs; a block naming any of them opts its schema in.
	 *
	 * @var array<int, string>
	 */
	public const FILE_VERBS = [self::READ_FILES, self::ADD_FILES, self::DELETE_FILES];

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
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
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

		if ($this->mayUseFiles(object: $object, verb: self::READ_FILES) === false) {
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
	 * @param string $verb     The file verb the change needs when the schema names file verbs: addFiles or deleteFiles.
	 *
	 * @return ObjectEntity The changeable object.
	 *
	 * @throws ObjectFileAccessDeniedException With 404 when the caller may not read it,
	 *                                         403 when the caller may read but not update it.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-changing-an-objects-files-follows-the-objects-update-rule-req-ofoa-003
	 */
	public function changeable(string $register, string $schema, string $id, string $verb = self::ADD_FILES): ObjectEntity {
		$object = $this->readable(register: $register, schema: $schema, id: $id);

		if ($this->mayChangeFiles(object: $object, verb: $verb) === false) {
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
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
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

		return $readable instanceof ObjectEntity
			&& $this->mayUseFiles(object: $readable, verb: self::READ_FILES) === true;
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

	/**
	 * Whether the caller may change an object's files with this verb.
	 *
	 * A schema that names file verbs asks the verb; one that names none asks
	 * `update` on the object, as before file verbs existed.
	 *
	 * @param ObjectEntity $object The object, already known to be readable.
	 * @param string       $verb   addFiles or deleteFiles.
	 *
	 * @return bool True when the caller may make the change.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function mayChangeFiles(ObjectEntity $object, string $verb = self::ADD_FILES): bool {
		$schema = $this->schemaOf(object: $object);
		if ($schema === null) {
			return false;
		}

		$names = $this->namesFileVerbs(schema: $schema, object: $object);
		if ($names === null) {
			return false;
		}

		if ($names === true) {
			return $this->allows(schema: $schema, object: $object, action: $verb);
		}

		return $this->mayUpdate(object: $object);
	}//end mayChangeFiles()

	/**
	 * Whether a file verb lets the caller through, when the schema names file verbs.
	 *
	 * True when the schema names none (the object rule alone decides then).
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $verb   The file verb.
	 *
	 * @return bool
	 */
	private function mayUseFiles(ObjectEntity $object, string $verb): bool {
		$schema = $this->schemaOf(object: $object);
		if ($schema === null) {
			return false;
		}

		$names = $this->namesFileVerbs(schema: $schema, object: $object);
		if ($names === false) {
			return true;
		}

		return $names === true && $this->allows(schema: $schema, object: $object, action: $verb);
	}//end mayUseFiles()

	/**
	 * Whether the object's effective block names a file verb; null when it cannot be read.
	 *
	 * @param Schema       $schema The object's schema.
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool|null
	 */
	private function namesFileVerbs(Schema $schema, ObjectEntity $object): ?bool {
		try {
			$authorization = $this->permissionHandler->resolveAuthorization(schema: $schema, object: $object);
		} catch (Throwable $e) {
			return null;
		}

		return is_array($authorization) === true
			&& array_intersect(self::FILE_VERBS, array_keys($authorization)) !== [];
	}//end namesFileVerbs()

	/**
	 * The object's schema, or null when it cannot be found.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Schema|null
	 */
	private function schemaOf(ObjectEntity $object): ?Schema {
		$schemaId = $object->getSchema();
		if ($schemaId === null || $schemaId === '') {
			return null;
		}

		try {
			return $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}
	}//end schemaOf()

	/**
	 * Whether the permission handler grants an action on the object.
	 *
	 * @param Schema       $schema The schema.
	 * @param ObjectEntity $object The object.
	 * @param string       $action The action.
	 *
	 * @return bool
	 */
	private function allows(Schema $schema, ObjectEntity $object, string $action): bool {
		try {
			return $this->permissionHandler->hasPermission(
				schema: $schema,
				action: $action,
				userId: null,
				objectOwner: $object->getOwner(),
				_rbac: true,
				object: $object
			);
		} catch (Throwable $e) {
			return false;
		}
	}//end allows()
}//end class
