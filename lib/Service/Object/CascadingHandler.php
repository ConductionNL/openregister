<?php

/**
 * CascadingHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Handles cascading object creation for inversedBy relationships.
 *
 * This handler is responsible for:
 * - Pre-validation cascading of nested objects
 * - Creating related objects automatically
 * - Managing inversedBy relationships
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class CascadingHandler {
	/**
	 * Constructor for CascadingHandler.
	 *
	 * @param SaveObject $saveHandler Handler for saving objects.
	 * @param SchemaMapperInterface $schemaMapper Mapper for schema entities.
	 * @param UtilityHandler $utilityHandler Handler for utility operations.
	 * @param LoggerInterface $logger Logger for logging operations.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly SaveObject $saveHandler,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly UtilityHandler $utilityHandler,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle pre-validation cascading for inversedBy properties.
	 *
	 * This method processes nested objects in inversedBy relationships before validation.
	 * It automatically creates related objects and replaces them with their UUIDs.
	 *
	 * @param array $object Object data to process.
	 * @param Schema $schema Schema entity defining the structure.
	 * @param string|null $uuid Object UUID (generated if null).
	 * @param int $currentRegister Current register ID.
	 *
	 * @return ((array|mixed|string)[]|null|string)[] Array containing [processed object, uuid].
	 *
	 * @psalm-return list{array<array|mixed|string>, null|string}
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function handlePreValidationCascading(array $object, Schema $schema, ?string $uuid, ?int $currentRegister): array {
		$inversedByProperties = $this->inversedByProperties(schema: $schema);
		if (count($inversedByProperties) === 0) {
			return [$object, $uuid];
		}

		$uuid   = $uuid ?? \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
		$parent = new CascadeTarget(parentUuid: $uuid, register: $currentRegister);
		foreach ($inversedByProperties as $propertyName => $definition) {
			if (isset($object[$propertyName]) === false || empty($object[$propertyName]) === true) {
				continue;
			}

			$object[$propertyName] = $this->cascadeValue(value: $object[$propertyName], target: $parent->with(definition: $definition));
		}

		return [$object, $uuid];
	}//end handlePreValidationCascading()

	/**
	 * The schema's properties that carry an inversedBy, on the property or on its items.
	 *
	 * @param Schema $schema The schema of the object.
	 *
	 * @return array The inversedBy properties by name; empty when the schema cannot be read.
	 *
	 * @todo Move writeBack, removeAfterWriteBack and inversedBy from the items property to a configuration property.
	 */
	private function inversedByProperties(Schema $schema): array {
		try {
			// The schema object needs the save handler's URL generator.
			$urlGenerator = (new ReflectionClass($this->saveHandler))->getProperty('urlGenerator')->getValue($this->saveHandler);
			$schemaObject = $schema->getSchemaObject($urlGenerator);
			$properties   = json_decode(json_encode($schemaObject), associative: true)['properties'] ?? [];
		} catch (Exception $e) {
			return [];
		}

		return array_filter($properties, self::hasInversedBy(...));
	}//end inversedByProperties()

	/**
	 * Whether a property definition carries an inversedBy, on an array's items or on itself.
	 *
	 * @param array $property The property definition.
	 *
	 * @return bool
	 */
	private static function hasInversedBy(array $property): bool {
		if ($property['type'] === 'array' && isset($property['items']['inversedBy']) === true) {
			return true;
		}

		return isset($property['inversedBy']) === true;
	}//end hasInversedBy()

	/**
	 * A non-empty property value with its nested objects created and replaced by their uuids.
	 *
	 * @param mixed         $value  The property value.
	 * @param CascadeTarget $target The parent and the property definition.
	 *
	 * @return mixed The value to store in the property.
	 */
	private function cascadeValue(mixed $value, CascadeTarget $target): mixed {
		$definition = $target->definition();
		if ($definition['type'] === 'array' && isset($definition['items']['inversedBy']) === true) {
			return $this->cascadeItems(items: $value, target: $target->with(definition: $definition['items']));
		}

		if (isset($definition['inversedBy']) === true && $definition['type'] !== 'array') {
			return $this->cascadeNested(value: $value, target: $target);
		}

		return $value;
	}//end cascadeValue()

	/**
	 * A list with its nested objects created; uuids are kept and anything else is dropped.
	 *
	 * @param mixed         $items  The list value.
	 * @param CascadeTarget $target The parent and the items definition.
	 *
	 * @return array The created uuids, the uuids kept, and the nested objects whose create failed.
	 */
	private function cascadeItems(mixed $items, CascadeTarget $target): array {
		$cascaded = [];
		foreach ($items as $item) {
			if (is_array($item) === true && $this->utilityHandler->isUuid($item) === false) {
				// A failed create keeps the item, so the list does not come out empty.
				$cascaded[] = $this->createFor(objectData: $item, target: $target) ?? $item;
			} elseif (is_string($item) === true && $this->utilityHandler->isUuid($item) === true) {
				$cascaded[] = $item;
			}
		}

		return $cascaded;
	}//end cascadeItems()

	/**
	 * A single nested object created and replaced by its uuid; any other value as it is.
	 *
	 * @param mixed         $value  The property value.
	 * @param CascadeTarget $target The parent and the property definition.
	 *
	 * @return mixed The created uuid, or the value when it is no nested object or its create failed.
	 */
	private function cascadeNested(mixed $value, CascadeTarget $target): mixed {
		if (is_array($value) === true && $this->utilityHandler->isUuid($value) === false) {
			return $this->createFor(objectData: $value, target: $target) ?? $value;
		}

		return $value;
	}//end cascadeNested()

	/**
	 * Create one nested object under the target's parent.
	 *
	 * @param array         $objectData The nested object.
	 * @param CascadeTarget $target     The parent and the property definition.
	 *
	 * @return string|null The created uuid, or null when the create failed.
	 *
	 * @throws NotAuthorizedException When the caller may not create objects in the target schema.
	 */
	private function createFor(array $objectData, CascadeTarget $target): ?string {
		return $this->createRelatedObject(
			objectData: $objectData,
			definition: $target->definition(),
			parentUuid: $target->parentUuid,
			currentRegister: $target->register
		);
	}//end createFor()

	/**
	 * Create a related object and return its UUID.
	 *
	 * This method creates a nested object with an inverse relationship to the parent.
	 * It resolves the schema from the property definition and sets the inversedBy field.
	 *
	 * @param array $objectData Object data to create.
	 * @param array $definition Property definition containing schema reference.
	 * @param string $parentUuid UUID of the parent object.
	 * @param int|null $currentRegister Current register ID (nullable for seedData).
	 *
	 * @return string|null UUID of created object or null if creation failed.
	 *
	 * @throws NotAuthorizedException When the caller may not create objects in the target schema.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function createRelatedObject(
		array $objectData,
		array $definition,
		string $parentUuid,
		?int $currentRegister,
	): ?string {
		try {
			// Resolve schema reference to actual schema ID.
			$schemaRef = $definition['$ref'] ?? null;
			if ($schemaRef === null || $schemaRef === '') {
				return null;
			}

			// Extract schema slug from reference.
			$schemaSlug = null;
			if (str_contains($schemaRef, '#/components/schemas/') === true) {
				$schemaSlug = substr($schemaRef, strrpos($schemaRef, '/') + 1);
			}

			if ($schemaSlug === null || $schemaSlug === '') {
				return null;
			}

			$targetSchema = $this->findSchemaBySlug(slug: $schemaSlug);
			if ($targetSchema === null) {
				return null;
			}

			$this->saveHandler->assertMayCascadeCreate(schema: $targetSchema);

			// Get the register (use the same register as the parent object).
			$targetRegister = $currentRegister;

			// Add the inverse relationship to the parent object.
			$inversedBy = $definition['inversedBy'] ?? null;
			if ($inversedBy !== null && $inversedBy !== '') {
				$objectData[$inversedBy] = $parentUuid;
			}

			// Create the object.
			$createdObject = $this->saveHandler->saveObject(
				register: $targetRegister,
				schema: $targetSchema,
				data: self::withoutIdentity(objectData: $objectData),
				uuid: null,
				// Let it generate a new UUID.
				folderId: null,
				_rbac: true,
				// Use default RBAC for internal cascading operations.
				_multitenancy: true
				// Use default multitenancy for internal cascading operations.
			);

			// Track the created sub-object for inclusion in parent's @self.objects.
			$createdUuid = $createdObject->getUuid();
			if ($createdUuid !== null) {
				$this->saveHandler->trackCreatedSubObject($createdUuid, $createdObject->jsonSerialize());
			}

			return $createdUuid;
		} catch (NotAuthorizedException $e) {
			throw $e;
		} catch (Exception $e) {
			// Log error but don't expose details.
			$this->logger->error(
				message: '[CascadingHandler] Failed to create related object: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}//end try
	}//end createRelatedObject()

	/**
	 * The schema with this slug, compared case-insensitively.
	 *
	 * @param string $slug The schema slug from a `#/components/schemas/<slug>` reference.
	 *
	 * @return Schema|null The schema, or null when none has the slug.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function findSchemaBySlug(string $slug): ?Schema {
		foreach ($this->schemaMapper->findAll() as $schema) {
			if (strcasecmp(string1: $schema->getSlug(), string2: $slug) === 0) {
				return $schema;
			}
		}

		return null;
	}//end findSchemaBySlug()

	/**
	 * A nested object without the identity it carries.
	 *
	 * A nested inline object is always created, never updated. The save handler
	 * takes `id` or `@self.id` as the uuid to write to and looks that uuid up
	 * past the read filters, so a caller-chosen id there would overwrite the row
	 * of that uuid with no permission check. The new object gets its own uuid.
	 *
	 * @param array $objectData The nested object.
	 *
	 * @return array The nested object without `id`, `@self.id` and `@self.uuid`.
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	private static function withoutIdentity(array $objectData): array {
		unset($objectData['id']);
		if (is_array($objectData['@self'] ?? null) === true) {
			unset($objectData['@self']['id'], $objectData['@self']['uuid']);
		}

		return $objectData;
	}//end withoutIdentity()
}//end class
