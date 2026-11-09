<?php

/**
 * OpenRegister RelationCascadeHandler
 *
 * Handler for managing object relations and cascading operations.
 * Handles schema resolution, relation scanning, and cascading object creation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\UuidPattern;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Relation Cascade Handler
 *
 * Handles complex object relationship operations including:
 * - Schema and register reference resolution
 * - Relation scanning and detection
 * - Cascading object creation (inversedBy properties)
 * - Inverse relation write-back operations
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects\SaveObject
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex cascading logic with many relation scenarios
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class RelationCascadeHandler {
	/**
	 * The lookup scope of a caller that compares identities only: no RBAC, no tenancy.
	 */
	public const SYSTEM_SCOPE = ['_rbac' => false, '_multitenancy' => false];

	/**
	 * Constructor for RelationCascadeHandler.
	 *
	 * @param MagicObjectFinderInterface $objectEntityMapper Object entity data mapper.
	 * @param SchemaMapperInterface $schemaMapper Schema mapper for schema operations.
	 * @param RegisterMapperInterface $registerMapper Register mapper for register operations.
	 * @param LoggerInterface $logger Logger interface for logging operations.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly MagicObjectFinderInterface $objectEntityMapper,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolves a schema reference to a schema ID.
	 *
	 * This method handles various types of schema references:
	 * - Direct ID/UUID: "34", "21aab6e0-2177-4920-beb0-391492fed04b"
	 * - JSON Schema path references: "#/components/schemas/Contactgegevens"
	 * - URL references: "http://example.com/api/schemas/34"
	 * - Slug references: "contactgegevens"
	 *
	 * For path and URL references, it extracts the last part and matches against schema slugs (case-insensitive).
	 *
	 * @param string               $reference The schema reference to resolve.
	 * @param array<string, mixed> $scope     Named arguments for the schema lookup; {@see self::SYSTEM_SCOPE} reads the
	 *                                        schema without the caller's RBAC and tenancy, for callers that only
	 *                                        compare identities (metadata) and disclose nothing of the schema.
	 *
	 * @return null|numeric-string The resolved schema ID or null if not found.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function resolveSchemaReference(string $reference, array $scope = []): ?string {
		if (empty($reference) === true) {
			return null;
		}

		$cleanReference = $this->removeQueryParameters(reference: $reference);
		$byId = fn (string $id) => $this->schemaMapper->find(...(['id' => $id] + $scope));

		return $this->directIdMatch(reference: $cleanReference, find: $byId)
			?? $this->slugLookup(reference: $cleanReference, findAll: fn () => $this->schemaMapper->findAll(...$scope), kind: 'schema');
	}//end resolveSchemaReference()

	/**
	 * The id of the entity a direct-id reference (a numeric id or a UUID) names, or null when it is no direct id
	 * or names nothing.
	 *
	 * @param string   $reference The reference without query parameters.
	 * @param callable $find      Finds the entity by id; throws DoesNotExistException when there is none.
	 *
	 * @return string|null
	 */
	private function directIdMatch(string $reference, callable $find): ?string {
		if (is_numeric($reference) === false
			&& preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reference) !== 1
		) {
			return null;
		}

		try {
			return (string)$find($reference)->getId();
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end directIdMatch()

	/**
	 * The id of the entity whose slug is the reference's last path segment (the whole reference without a `/`); a failing lookup is logged and yields null.
	 *
	 * @param string   $reference The reference without query parameters.
	 * @param callable $findAll   Lists the candidate entities.
	 * @param string   $kind      What is looked up ("schema" or "register"), for the log line.
	 *
	 * @return null|numeric-string
	 */
	private function slugLookup(string $reference, callable $findAll, string $kind): ?string {
		try {
			return $this->slugMatch(entities: $findAll(), slug: array_slice(explode('/', $reference), -1)[0]);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[RelationCascadeHandler] Error finding ' . $kind . ' by slug: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		return null;
	}//end slugLookup()

	/**
	 * Removes query parameters from a reference string.
	 *
	 * @param string $reference The reference string to clean.
	 *
	 * @return string The cleaned reference without query parameters.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function removeQueryParameters(string $reference): string {
		return explode('?', $reference, 2)[0];
	}//end removeQueryParameters()

	/**
	 * The id of the first entity whose slug equals the given slug, ignoring case.
	 *
	 * @param iterable<Schema|Register> $entities The schemas or registers.
	 * @param string                    $slug     The slug to match.
	 *
	 * @return null|numeric-string
	 */
	private function slugMatch(iterable $entities, string $slug): ?string {
		foreach ($entities as $entity) {
			if (strcasecmp($entity->getSlug(), $slug) === 0) {
				return (string)$entity->getId();
			}
		}

		return null;
	}//end slugMatch()

	/**
	 * Resolves a register reference to a register ID.
	 *
	 * This method handles various types of register references:
	 * - Direct ID/UUID: "34", "21aab6e0-2177-4920-beb0-391492fed04b"
	 * - URL path references: "https://api.example.com/api/registers/1"
	 * - Slug references: "demo-register"
	 *
	 * For URL references, it extracts the last numeric part.
	 * For non-numeric references, it attempts to find the register by slug (case-insensitive).
	 *
	 * @param string $reference The register reference to resolve.
	 *
	 * @return null|numeric-string The resolved register ID or null if not found.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function resolveRegisterReference(string $reference): ?string {
		if (empty($reference) === true) {
			return null;
		}

		$cleanReference = $this->removeQueryParameters(reference: $reference);
		$byId = fn (string $id) => $this->registerMapper->find(id: $id);

		return $this->directIdMatch(reference: $cleanReference, find: $byId)
			?? $this->slugLookup(reference: $cleanReference, findAll: fn () => $this->registerMapper->findAll(), kind: 'register');
	}//end resolveRegisterReference()

	/**
	 * Recursively scans for relations in data that need to be resolved.
	 *
	 * This method walks through the data array looking for properties that contain
	 * object references (UUIDs, URLs, or numeric IDs) that need to be resolved.
	 *
	 * @param array $data The data array to scan.
	 * @param string $prefix The current property path prefix.
	 * @param null|Schema $schema The schema to validate against.
	 *
	 * @return array Array of relation paths that need resolution.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function scanForRelations(array $data, string $prefix = '', ?Schema $schema = null): array {
		$relations = [];
		foreach ($data as $key => $value) {
			// Special metadata fields are never relations.
			if (in_array($key, ['_self', '_schema', '_register'], true) === true) {
				continue;
			}

			// A top-level key is its own path, so an integer key stays an integer.
			$path = $key;
			if ($prefix !== '') {
				$path = "{$prefix}.{$key}";
			}

			$relations = array_merge($relations, $this->relationsAt(path: $path, value: $value, schema: $schema));
		}

		return $relations;
	}//end scanForRelations()

	/**
	 * The relation paths one value contributes: itself, or those found inside it.
	 *
	 * @param int|string  $path   The value's path.
	 * @param mixed       $value  The value.
	 * @param null|Schema $schema The schema to read `$ref` declarations from.
	 *
	 * @return array
	 */
	private function relationsAt(int|string $path, mixed $value, ?Schema $schema): array {
		$hasRef = $this->declaresRef(schema: $schema, path: $path);
		if (is_array($value) === false) {
			return $this->singleRelation(path: $path, value: $value, hasRef: $hasRef);
		}

		// An array holding a reference is one relation; any other array is scanned for nested ones.
		if ($this->isArrayOfReferences(array: $value) === true) {
			return [$path];
		}

		return $this->scanForRelations(data: $value, prefix: $path, schema: $schema);
	}//end relationsAt()

	/**
	 * Whether the schema declares a `$ref` on the property at a path; false without a schema.
	 *
	 * @param null|Schema $schema The schema, or null.
	 * @param int|string  $path   The property path.
	 *
	 * @return bool
	 */
	private function declaresRef(?Schema $schema, int|string $path): bool {
		if ($schema === null) {
			return false;
		}

		$propertyDef = $this->getPropertyDefinition(properties: $schema->getProperties(), propertyPath: explode('.', $path));

		return isset($propertyDef['$ref']) === true;
	}//end declaresRef()

	/**
	 * The path of a scalar that is a relation (a reference that either has a `$ref` or looks like an object
	 * reference), or nothing.
	 *
	 * @param int|string $path   The value's path.
	 * @param mixed      $value  The value.
	 * @param bool       $hasRef Whether the schema declares a `$ref` for it.
	 *
	 * @return array
	 */
	private function singleRelation(int|string $path, mixed $value, bool $hasRef): array {
		if (is_string($value) === true
			&& $this->isReference(value: $value) === true
			&& ($hasRef === true || $this->looksLikeObjectReference(value: $value) === true)
		) {
			return [$path];
		}

		return [];
	}//end singleRelation()

	/**
	 * Gets a property definition from properties array by path.
	 *
	 * @param array $properties The properties array.
	 * @param array $propertyPath The property path parts.
	 *
	 * @return array The property definition or empty array.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function getPropertyDefinition(array $properties, array $propertyPath): array {
		$current = $properties;
		foreach ($propertyPath as $part) {
			if (isset($current[$part]) === false) {
				return [];
			}

			$current = $current[$part];
		}

		return $current;
	}//end getPropertyDefinition()

	/**
	 * Checks if an array contains references.
	 *
	 * @param array $array The array to check.
	 *
	 * @return bool True if array contains references.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function isArrayOfReferences(array $array): bool {
		foreach ($array as $item) {
			if (is_string($item) === true && $this->isReference(value: $item) === true) {
				return true;
			}
		}

		return false;
	}//end isArrayOfReferences()

	/**
	 * Checks if a value looks like an object reference.
	 *
	 * This is the relation RESOLUTION path (it accepts numeric ids and `/api/`
	 * URLs so cascade write-back can resolve them to UUIDs), which is a broader
	 * concern than the `@self.relations` RECORDING rule SaveObjects takes
	 * from RelationDetectionTrait. It deliberately does
	 * NOT carry the loose "length + separator" heuristic, so scalars such as
	 * dates or enum values are never matched here either.
	 *
	 * @param string $value The value to check.
	 *
	 * @return bool True if it looks like a reference.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function looksLikeObjectReference(string $value): bool {
		// A UUID, with or without dashes or a word prefix.
		if (preg_match(UuidPattern::ANY, $value) === 1) {
			return true;
		}

		// Numeric ID.
		if (preg_match('/^[0-9]+$/', $value) === 1) {
			return true;
		}

		// URL pattern containing /objects/.
		if (str_contains($value, '/objects/') === true) {
			return true;
		}

		return false;
	}//end looksLikeObjectReference()

	/**
	 * Determines if a value is a reference to another object.
	 *
	 * A reference can be:
	 * - A UUID string (with or without dashes)
	 * - A prefixed UUID (e.g., "id-uuid")
	 * - A URL containing /objects/ or /api/
	 * - A numeric ID (if > 0)
	 *
	 * @param string $value The value to check.
	 *
	 * @return bool True if value is a reference.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function isReference(string $value): bool {
		// A UUID, with or without dashes or a word prefix.
		if (preg_match(UuidPattern::ANY, $value) === 1) {
			return true;
		}

		// A URL pattern, or a numeric id above zero.
		return str_contains($value, '/objects/') === true
			|| str_contains($value, '/api/') === true
			|| (is_numeric($value) === true && (int)$value > 0);
	}//end isReference()

	/**
	 * Updates object relations by resolving references to actual object UUIDs.
	 *
	 * This method walks through the object data and replaces references
	 * (URLs, IDs) with the corresponding object UUIDs.
	 *
	 * @param ObjectEntity $objectEntity The object entity being updated.
	 * @param array $data The object data containing relations.
	 * @param null|Schema $schema The schema for validation.
	 *
	 * @return ObjectEntity The updated object entity.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function updateObjectRelations(ObjectEntity $objectEntity, array $data, ?Schema $schema = null): ObjectEntity {
		// Scan for relations.
		$relations = $this->scanForRelations(data: $data, prefix: '', schema: $schema);

		if (empty($relations) === true) {
			return $objectEntity;
		}

		$objectData = $objectEntity->getObject();

		// Resolve each relation.
		foreach ($relations as $relationPath) {
			$this->resolveRelationPath(objectData: $objectData, relationPath: $relationPath);
		}

		$objectEntity->setObject($objectData);

		return $objectEntity;
	}//end updateObjectRelations()

	/**
	 * Resolves a relation path in object data.
	 *
	 * @param array $objectData The object data (passed by reference).
	 * @param string $relationPath The dot-notation path to the relation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function resolveRelationPath(array &$objectData, string $relationPath): void {
		$parts = explode('.', $relationPath);
		$lastKey = array_pop($parts);
		$current = &$objectData;

		// Navigate to the parent of the target property.
		foreach ($parts as $part) {
			if (isset($current[$part]) === false) {
				return;
			}

			$current = &$current[$part];
		}

		if (isset($current[$lastKey]) === true) {
			$current[$lastKey] = $this->resolvedValue(value: $current[$lastKey]);
		}
	}//end resolveRelationPath()

	/**
	 * A value with its references resolved to UUIDs: a list keeps only what resolves, a string what resolves or
	 * itself, anything else itself.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return mixed
	 */
	private function resolvedValue(mixed $value): mixed {
		if (is_array($value) === true) {
			return $this->resolvedList(references: $value);
		}

		if (is_string($value) === true) {
			return $this->extractUuidFromReference(reference: $value) ?? $value;
		}

		return $value;
	}//end resolvedValue()

	/**
	 * The UUIDs the string items of a list resolve to, in order; items that do not resolve are dropped.
	 *
	 * @param array $references The list items.
	 *
	 * @return list<string>
	 */
	private function resolvedList(array $references): array {
		$resolved = [];
		foreach ($references as $ref) {
			if (is_string($ref) === false) {
				continue;
			}

			$uuid = $this->extractUuidFromReference(reference: $ref);
			if ($uuid !== null) {
				$resolved[] = $uuid;
			}
		}

		return $resolved;
	}//end resolvedList()

	/**
	 * Extracts UUID from a reference string.
	 *
	 * @param string $reference The reference string.
	 *
	 * @return string|null The extracted UUID or null.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::isValid is standard Symfony UID pattern
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function extractUuidFromReference(string $reference): ?string {
		if (Uuid::isValid($reference) === true) {
			return $reference;
		}

		if (is_numeric($reference) === true) {
			return $this->uuidOfObjectId(id: (int)$reference);
		}

		$tail = $this->objectUrlTail(reference: $reference);
		if (Uuid::isValid($tail) === true) {
			return $tail;
		}

		return null;
	}//end extractUuidFromReference()

	/**
	 * The UUID of the object with a numeric id, or null when there is none.
	 *
	 * @param int $id The object id.
	 *
	 * @return string|null
	 */
	private function uuidOfObjectId(int $id): ?string {
		try {
			return $this->objectEntityMapper->find($id)->getUuid();
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end uuidOfObjectId()

	/**
	 * What follows the single `/objects/` of a URL, without surrounding slashes; '' when there is not exactly one.
	 *
	 * @param string $reference The reference string.
	 *
	 * @return string
	 */
	private function objectUrlTail(string $reference): string {
		$parts = explode('/objects/', $reference);
		if (count($parts) !== 2) {
			return '';
		}

		return trim($parts[1], '/');
	}//end objectUrlTail()

	/**
	 * Cascades object creation for inversedBy properties.
	 *
	 * This method handles the creation of related objects before the main object
	 * is validated and saved (pre-validation cascading).
	 *
	 * @param ObjectEntity $objectEntity The parent object entity.
	 * @param Schema $schema The schema of the parent object.
	 * @param array $data The object data containing nested objects.
	 *
	 * @return array The updated data with created object UUIDs.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function cascadeObjects(ObjectEntity $objectEntity, Schema $schema, array $data): array {
		foreach ($schema->getProperties() as $propertyName => $property) {
			// Only a non-empty value of an inversedBy property cascades.
			if (isset($property['inversedBy']) === false || empty($data[$propertyName]) === true) {
				continue;
			}

			$data[$propertyName] = $this->cascadedValue(objectEntity: $objectEntity, property: $property, propData: $data[$propertyName]);
		}

		return $data;
	}//end cascadeObjects()

	/**
	 * The value an inversedBy property holds after cascading: the created UUIDs of an array property, the created
	 * UUID of a single nested object, or the value itself.
	 *
	 * @param ObjectEntity $objectEntity The parent object entity.
	 * @param array        $property     The property definition.
	 * @param mixed        $propData     The property's non-empty value.
	 *
	 * @return mixed
	 */
	private function cascadedValue(ObjectEntity $objectEntity, array $property, mixed $propData): mixed {
		if (isset($property['type']) === true && $property['type'] === 'array') {
			return $this->cascadeMultipleObjects(objectEntity: $objectEntity, property: $property, propData: $propData);
		}

		if ($this->isNestedObject(value: $propData) === false) {
			return $propData;
		}

		return $this->cascadeSingleObject(_objectEntity: $objectEntity, _definition: $property, _object: $propData) ?? $propData;
	}//end cascadedValue()

	/**
	 * Checks if array contains only scalar values.
	 *
	 * @param array $array The array to check.
	 *
	 * @return bool True if all values are scalar.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function isArrayOfScalars(array $array): bool {
		foreach ($array as $value) {
			if (is_array($value) === true || is_object($value) === true) {
				return false;
			}
		}

		return true;
	}//end isArrayOfScalars()

	/**
	 * Cascades creation of multiple related objects.
	 *
	 * @param ObjectEntity $objectEntity The parent object entity.
	 * @param array $property The property definition.
	 * @param array $propData The property data (array of objects).
	 *
	 * @return string[] Array of created object UUIDs.
	 *
	 * @psalm-return list<string>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function cascadeMultipleObjects(ObjectEntity $objectEntity, array $property, array $propData): array {
		$createdUuids = [];
		foreach ($propData as $object) {
			// An item that already is a UUID reference is kept.
			if (is_string($object) === true && Uuid::isValid($object) === true) {
				$createdUuids[] = $object;
				continue;
			}

			$created = $this->createdFrom(objectEntity: $objectEntity, property: $property, object: $object);
			$createdUuids = array_merge($createdUuids, $created);
		}

		return $createdUuids;
	}//end cascadeMultipleObjects()

	/**
	 * The UUID of the object created from a nested-object list item; nothing for any other item.
	 *
	 * @param ObjectEntity $objectEntity The parent object entity.
	 * @param array        $property     The property definition.
	 * @param mixed        $object       The list item.
	 *
	 * @return list<string>
	 */
	private function createdFrom(ObjectEntity $objectEntity, array $property, mixed $object): array {
		if ($this->isNestedObject(value: $object) === false) {
			return [];
		}

		$uuid = $this->cascadeSingleObject(_objectEntity: $objectEntity, _definition: $property, _object: $object);
		if ($uuid !== null) {
			return [$uuid];
		}

		return [];
	}//end createdFrom()

	/**
	 * Whether a value is a nested object: an array holding at least one array or object.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isNestedObject(mixed $value): bool {
		return is_array($value) === true && $this->isArrayOfScalars(array: $value) === false;
	}//end isNestedObject()

	/**
	 * Cascades creation of a single related object.
	 *
	 * @param ObjectEntity $_objectEntity The parent object entity.
	 * @param array $_definition The property definition containing $ref and inversedBy.
	 * @param array $_object The nested object data to create.
	 *
	 * @return null The UUID of the created object or null.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function cascadeSingleObject(ObjectEntity $_objectEntity, array $_definition, array $_object) {
		// TODO: Implement actual cascading logic.
		// This requires access to ObjectService which would create circular dependency.
		// Need to refactor this to use event system or separate coordination service.
		$this->logger->warning(
			message: '[RelationCascadeHandler] Cascade object creation not yet implemented in extracted handler',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return null;
	}//end cascadeSingleObject()

	/**
	 * Handles inverse relation write-back operations.
	 *
	 * After an object is saved, this method updates related objects to maintain
	 * bidirectional relationship integrity (inversedBy properties).
	 *
	 * @param ObjectEntity $_objectEntity The saved object entity.
	 * @param Schema $_schema The schema of the object.
	 * @param array $data The object data.
	 *
	 * @return array The updated data after write-back operations.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function handleInverseRelationsWriteBack(ObjectEntity $_objectEntity, Schema $_schema, array $data): array {
		// TODO: Implement inverse relation write-back.
		// This requires access to ObjectService which would create circular dependency.
		// Need to refactor this to use event system or separate coordination service.
		$this->logger->warning(
			message: '[RelationCascadeHandler] Inverse relation write-back not yet implemented in extracted handler',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return $data;
	}//end handleInverseRelationsWriteBack()
}//end class
