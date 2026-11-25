<?php

/**
 * Rewrites the node ids an object holds when its register's documents move.
 *
 * An object holds its folder's id and, in each file-typed property, the ids of
 * its files. After the move those ids name nodes on the old storage; this class
 * replaces them with the copies' ids and records one audit trail entry per
 * object it changed, action `document-store-move`. It reads every row of every
 * schema of the register in system context, so no object is skipped because the
 * caller could not see it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Generator;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * Object folder and file property rewrite for one register.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
class DocumentStoreObjects {

	/**
	 * The audit trail action of a rewritten object.
	 */
	public const ACTION = 'document-store-move';

	/**
	 * How many rows one read of a schema's table returns.
	 */
	private const CHUNK_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemaMapper The register's schemas.
	 * @param MagicMapperInterface       $objects     Reads every row of a schema's table and stores the rewritten object.
	 * @param AuditTrailMapperInterface  $auditTrails Records the rewrite.
	 * @param DocumentReferenceMap  $map          The shapes a file property takes.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly MagicMapperInterface $objects,
		private readonly AuditTrailMapperInterface $auditTrails,
		private readonly DocumentReferenceMap $map,
	) {
	}//end __construct()

	/**
	 * Replace every moved id an object of the register holds.
	 *
	 * @param Register        $register         The register whose documents moved.
	 * @param array<int, int> $moved            Old node id => new node id, files and folders.
	 * @param array<int, int> $withoutVersions  Old file id => old file id, files copied without their earlier versions.
	 *
	 * @return int The number of objects changed.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function rewrite(Register $register, array $moved, array $withoutVersions = []): int {
		$changed = 0;
		foreach ($register->getSchemas() as $schemaId) {
			$schema = $this->schemaMapper->find(id: $schemaId, _extend: [], _rbac: false, _multitenancy: false);
			$properties = self::fileProperties(schema: $schema);
			foreach ($this->rows(register: $register, schema: $schema) as $object) {
				if ($this->rewriteOne(
					object: $object,
					context: [$register, $schema, $properties],
					moved: $moved,
					withoutVersions: $withoutVersions
				) === true) {
					$changed++;
				}
			}
		}

		return $changed;
	}//end rewrite()

	/**
	 * Every row of a schema's table in a register, deleted ones included, in system context.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return Generator<int, ObjectEntity>
	 */
	private function rows(Register $register, Schema $schema): Generator {
		$filters = ['_includeDeleted' => true, '_rbac' => false, '_multitenancy' => false];
		$offset = 0;
		do {
			$objects = $this->objects->findAllInRegisterSchemaTable(
				table: new RegisterSchema(register: $register, schema: $schema),
				listing: new ListQuery(limit: self::CHUNK_SIZE, offset: $offset, filters: $filters)
			);
			$full = (count($objects) === self::CHUNK_SIZE);
			yield from $objects;
			$offset += self::CHUNK_SIZE;
		} while ($full === true);
	}//end rows()

	/**
	 * Rewrite one object, store it and record it, when it holds a moved id.
	 *
	 * @param ObjectEntity                              $object          The object.
	 * @param array{0: Register, 1: Schema, 2: list<string>} $context    Its register, schema and file properties.
	 * @param array<int, int>                           $moved           Old node id => new node id.
	 * @param array<int, int>                           $withoutVersions Files copied without their earlier versions.
	 *
	 * @return bool Whether the object changed.
	 */
	private function rewriteOne(ObjectEntity $object, array $context, array $moved, array $withoutVersions): bool {
		[$register, $schema, $properties] = $context;
		$old = clone $object;
		$changed = false;

		$folder = $object->getFolder();
		if (is_string($folder) === true && ctype_digit($folder) === true && isset($moved[(int)$folder]) === true) {
			$object->setFolder((string)$moved[(int)$folder]);
			$changed = true;
		}

		$data = $object->getObject();
		$held = $this->map->objectFileIds(object: $data, properties: $properties);
		$rewritten = $this->map->rewriteObject(object: $data, properties: $properties, moved: $moved);
		if ($rewritten !== $data) {
			$object->setObject($rewritten);
			$changed = true;
		}

		if ($changed === false) {
			return false;
		}

		$this->objects->update(entity: $object, register: $register, schema: $schema, oldEntity: $old);
		$this->auditTrails->createAuditTrail(
			old: $old,
			new: $object,
			action: self::ACTION,
			cascadeContext: self::context(held: $held, withoutVersions: $withoutVersions)
		);
		return true;
	}//end rewriteOne()

	/**
	 * What the audit entry records beyond the change: files copied without earlier versions.
	 *
	 * @param array<string, list<int>> $held            Property => the file ids it held.
	 * @param array<int, int>          $withoutVersions Files copied without their earlier versions.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function context(array $held, array $withoutVersions): ?array {
		$without = [];
		foreach ($held as $ids) {
			foreach ($ids as $id) {
				if (isset($withoutVersions[$id]) === true) {
					$without[] = $id;
				}
			}
		}

		if ($without === []) {
			return null;
		}

		return ['documentStoreMove' => ['withoutEarlierVersions' => $without]];
	}//end context()

	/**
	 * The names of a schema's file-typed properties (`file`, or an array of `file`).
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return list<string>
	 */
	private static function fileProperties(Schema $schema): array {
		$names = [];
		foreach ($schema->getProperties() as $name => $config) {
			if (is_array($config) === false) {
				continue;
			}

			$type = ($config['type'] ?? '');
			if ($type === 'file' || ($type === 'array' && (($config['items']['type'] ?? '') === 'file'))) {
				$names[] = (string)$name;
			}
		}

		return $names;
	}//end fileProperties()
}//end class
