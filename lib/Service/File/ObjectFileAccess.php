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
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator;
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
	 * The matrix key that declares whether a schema is in the file-verb regime
	 * through its matrix. A `rowsFrom` matrix is in it unless this is false,
	 * so a provider that fails cannot take the regime away with its rows.
	 *
	 * @var string
	 */
	public const MATRIX_FILE_VERBS = 'fileVerbs';

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
	 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/file-actions/spec.md
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
	 * Whether the object's schema has opted into file verbs; null when it cannot be read.
	 *
	 * 🔴 DECIDED FROM THE DECLARED BLOCK AS WELL AS THE COMPILED ONE. A matrix
	 * row past its `until`, and provider rows that did not arrive (the listener
	 * threw or answered nothing), compile to nothing. Read from the compiled
	 * block alone, a schema whose only file-verb rows were such rows would fall
	 * out of the file regime and back to the object rule, opening its files to
	 * every object reader the moment a grant expired. An expired or failed
	 * source compiles to no grant, never to no regime.
	 *
	 * Opted in when the compiled or the declared block names a file verb, when
	 * a declared matrix row names one in its actions (expired or not), when the
	 * matrix declares `fileVerbs` other than false, or when it names a
	 * `rowsFrom` provider and does not declare `fileVerbs: false`.
	 *
	 * @param Schema       $schema The object's schema.
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool|null
	 */
	private function namesFileVerbs(Schema $schema, ObjectEntity $object): ?bool {
		try {
			$authorization = $this->permissionHandler->resolveAuthorization(schema: $schema, object: $object);
			$declared      = $this->permissionHandler->resolveDeclaredAuthorization(schema: $schema, object: $object);
		} catch (Throwable $e) {
			return null;
		}

		return self::blockNamesFileVerbs(block: $authorization) === true
			|| self::blockNamesFileVerbs(block: $declared) === true
			|| self::matrixNamesFileVerbs(block: $declared) === true;
	}//end namesFileVerbs()

	/**
	 * Whether an authorization block names a file verb as one of its actions.
	 *
	 * @param mixed $block The block.
	 *
	 * @return bool
	 */
	private static function blockNamesFileVerbs(mixed $block): bool {
		return is_array($block) === true
			&& array_intersect(self::FILE_VERBS, array_keys($block)) !== [];
	}//end blockNamesFileVerbs()

	/**
	 * Whether a block's declared matrix opts into file verbs: an explicit
	 * `fileVerbs: true`, or any declared row (expired or not) naming one.
	 *
	 * @param mixed $block The declared block.
	 *
	 * @return bool
	 */
	private static function matrixNamesFileVerbs(mixed $block): bool {
		if (is_array($block) === false) {
			return false;
		}

		$matrix = ($block[DepartmentMatrixCompiler::KEY] ?? null);
		if (is_array($matrix) === false) {
			return false;
		}

		// A provider's rows may carry file verbs, and a provider that fails
		// answers nothing: a `rowsFrom` matrix is therefore in the file regime
		// unless the schema opts out with `fileVerbs: false`. Any other value
		// of `fileVerbs` (true, "true", 1: the validator refuses the
		// non-booleans) opts in. Fail closed: a doubt is no file access.
		if (array_key_exists(self::MATRIX_FILE_VERBS, $matrix) === true) {
			if ($matrix[self::MATRIX_FILE_VERBS] !== false) {
				return true;
			}
		} else if (is_string($matrix[DepartmentMatrixValidator::ROWS_FROM_KEY] ?? null) === true) {
			return true;
		}

		$rows = ($matrix['rows'] ?? []);
		if (is_array($rows) === false) {
			return false;
		}

		foreach ($rows as $row) {
			$actions = [];
			if (is_array($row) === true) {
				$actions = ($row['actions'] ?? []);
			}

			if (is_array($actions) === true && array_intersect(self::FILE_VERBS, $actions) !== []) {
				return true;
			}
		}

		return false;
	}//end matrixNamesFileVerbs()

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
