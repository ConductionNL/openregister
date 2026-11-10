<?php

/**
 * OpenRegister InverseRelations
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Formats\UuidFormat;
use Psr\Log\LoggerInterface;

/**
 * Keeps relations two-way: a saved object joins the relations of the objects it points at.
 *
 * Both `$ref` forms are in real use across the fleet's register definitions:
 * "#/components/schemas/organisation" (pointer) and "organisation" (bare slug). A
 * property with no `$ref` is not a relation and is skipped silently; a `$ref` that is
 * authored but cannot be read is a warning, because the declared relation will then
 * quietly not be maintained.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class InverseRelations {

	/**
	 * @param MagicMapperInterface  $objects Reads and updates the related objects.
	 * @param SchemaMapperInterface $schemas Resolves the target schema.
	 * @param LoggerInterface       $logger  Records what was skipped.
	 */
	public function __construct(
		private readonly MagicMapperInterface $objects,
		private readonly SchemaMapperInterface $schemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Add a saved object to the relations of every object it points at.
	 *
	 * @param ObjectEntity         $saved The saved object.
	 * @param InverseRelationWrite $write The write, carrying its register and schema.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function update(ObjectEntity $saved, InverseRelationWrite $write): void {
		$relations = ($saved->getRelations() ?? []);
		$write->savedUuid = $saved->getUuid();
		$this->logger->debug(
			message: '[SaveObject] updateInverseRelations called',
			context: ['savedObjectUuid' => $write->savedUuid, 'relationsCount' => count($relations), 'schemaId' => $write->schema->getId()]
		);

		if ($relations !== []) {
			$write->schemaProperties = ($write->schema->getProperties() ?? []);
			$this->writeAll(relations: $relations, write: $write);
		}
	}//end update()

	/**
	 * Write the relations that hold a UUID.
	 *
	 * @param array                $relations The related UUIDs, by property path.
	 * @param InverseRelationWrite $write     The write.
	 *
	 * @return void
	 */
	private function writeAll(array $relations, InverseRelationWrite $write): void {
		foreach ($relations as $propertyPath => $relatedUuid) {
			if (is_string($relatedUuid) === true && UuidFormat::isCanonical(value: $relatedUuid) === true) {
				$write->propertyPath = (string)$propertyPath;
				$write->relatedUuid = $relatedUuid;
				$this->writeOne(write: $write);
			}
		}
	}//end writeAll()

	/**
	 * Add the saved object to the one object a relation points at.
	 *
	 * A failure is logged and the next relation is processed.
	 *
	 * @param InverseRelationWrite $write The write, on one relation.
	 *
	 * @return void
	 */
	private function writeOne(InverseRelationWrite $write): void {
		try {
			$targetSchema = $this->targetSchema(write: $write);
			if ($targetSchema !== null) {
				$this->append(write: $write, related: $this->relatedObject(write: $write, schema: $targetSchema));
			}
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[SaveObject] Failed to update inverse relation',
				context: ['savedUuid' => $write->savedUuid, 'relatedUuid' => $write->relatedUuid, 'error' => $e->getMessage()]
			);
		}
	}//end writeOne()

	/**
	 * The object a relation points at, read without the caller's access limits.
	 *
	 * @param InverseRelationWrite $write  The write, on one relation.
	 * @param Schema               $schema The target schema.
	 *
	 * @return ObjectEntity The object.
	 */
	private function relatedObject(InverseRelationWrite $write, Schema $schema): ObjectEntity {
		return $this->objects->find(
			identifier: $write->relatedUuid,
			register: $write->register,
			schema: $schema,
			includeDeleted: false,
			_rbac: false,
			_multitenancy: false
		);
	}//end relatedObject()

	/**
	 * The schema a relation's property points at, or null when it is no resolvable relation.
	 *
	 * @param InverseRelationWrite $write The write, on one relation.
	 *
	 * @return Schema|null The target schema.
	 */
	private function targetSchema(InverseRelationWrite $write): ?Schema {
		$slug = $this->targetSlug(write: $write);
		if ($slug === null) {
			return null;
		}

		try {
			return $this->schemas->find(id: $slug, _rbac: false, _multitenancy: false);
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[SaveObject] Could not resolve target schema',
				context: ['targetSchemaSlug' => $slug, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end targetSchema()

	/**
	 * The schema slug a relation's property references, or null when there is none.
	 *
	 * @param InverseRelationWrite $write The write, on one relation.
	 *
	 * @return string|null The target schema slug.
	 */
	private function targetSlug(InverseRelationWrite $write): ?string {
		$baseProperty = explode('.', $write->propertyPath)[0];
		$property = ($write->schemaProperties[$baseProperty] ?? null);
		if ($property === null) {
			$this->logger->debug(message: '[SaveObject] No property config for relation', context: ['property' => $baseProperty]);
			return null;
		}

		$ref = self::refOf(property: $property);
		if (empty($ref) === true || is_string($ref) === false) {
			return null;
		}

		return $this->slugOfRef(ref: $ref, write: $write);
	}//end targetSlug()

	/**
	 * The `$ref` of a property; for an array it sits on the items.
	 *
	 * @param mixed $property The property definition.
	 *
	 * @return mixed The reference, empty when there is none.
	 */
	private static function refOf(mixed $property): mixed {
		$ref = ($property['$ref'] ?? '');
		if (empty($ref) === true && isset($property['items']['$ref']) === true) {
			return $property['items']['$ref'];
		}

		return $ref;
	}//end refOf()

	/**
	 * The slug in a `$ref`; a URL or a malformed pointer is reported instead of looked up.
	 *
	 * @param string               $ref   The reference.
	 * @param InverseRelationWrite $write The write, on one relation.
	 *
	 * @return string|null The slug.
	 */
	private function slugOfRef(string $ref, InverseRelationWrite $write): ?string {
		if (preg_match('~^\#/components/schemas/(.+)$~', $ref, $matches) === 1) {
			return $matches[1];
		}

		if (preg_match('~^[A-Za-z][A-Za-z0-9_-]*$~', $ref) === 1) {
			return $ref;
		}

		$this->logger->warning(
			message: '[SaveObject] Relation skipped: $ref is neither a "#/components/schemas/<slug>" pointer nor a bare schema slug',
			context: ['property' => explode('.', $write->propertyPath)[0], 'ref' => $ref, 'schema' => $write->schema->getSlug()]
		);
		return null;
	}//end slugOfRef()

	/**
	 * Add the saved object's UUID to a related object's relations, unless it is already there.
	 *
	 * @param InverseRelationWrite $write   The write, on one relation.
	 * @param ObjectEntity         $related The object the relation points at.
	 *
	 * @return void
	 */
	private function append(InverseRelationWrite $write, ObjectEntity $related): void {
		$context = ['savedUuid' => $write->savedUuid, 'relatedUuid' => $write->relatedUuid];
		$relations = ($related->getRelations() ?? []);
		if (in_array($write->savedUuid, $relations, true) === true) {
			$this->logger->debug(message: '[SaveObject] Object already in related object\'s relations', context: $context);
			return;
		}

		$relations[] = $write->savedUuid;
		$related->setRelations($relations);
		$related->setUpdated(new DateTime());
		$this->objects->update($related);

		$this->logger->debug(message: '[SaveObject] Updated inverse relation', context: $context + ['newRelationsCount' => count($relations)]);
	}//end append()
}//end class
