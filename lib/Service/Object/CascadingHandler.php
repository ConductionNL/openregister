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
use OCA\OpenRegister\Db\ObjectEntity;
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
	 * @param SaveObjectInterface $saveHandler Handler for saving objects.
	 * @param SchemaMapperInterface $schemaMapper Mapper for schema entities.
	 * @param UtilityHandler $utilityHandler Handler for utility operations.
	 * @param LoggerInterface $logger Logger for logging operations.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly SaveObjectInterface $saveHandler,
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

		$uuid    = $uuid ?? \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
		$parent  = new CascadeTarget(parentUuid: $uuid, register: $currentRegister);
		$targets = array_map(fn (array $definition): CascadeTarget => $parent->with(definition: $definition), $inversedByProperties);

		return [$this->cascadeProperties(object: $object, targets: $targets), $uuid];
	}//end handlePreValidationCascading()

	/**
	 * Cascade every filled inversedBy property of an object.
	 *
	 * @param array                        $object  The object data.
	 * @param array<string, CascadeTarget> $targets By inversedBy property name: the parent the created
	 *                                              objects point back to, with that property's definition.
	 *
	 * @return array The object data with its cascaded values
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function cascadeProperties(array $object, array $targets): array {
		foreach ($targets as $propertyName => $target) {
			if (isset($object[$propertyName]) === false || empty($object[$propertyName]) === true) {
				continue;
			}

			$object[$propertyName] = $this->cascadeValue(value: $object[$propertyName], target: $target);
		}

		return $object;
	}//end cascadeProperties()

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
			array_push($cascaded, ...$this->cascadeItem(item: $item, target: $target));
		}

		return $cascaded;
	}//end cascadeItems()

	/**
	 * One list item cascaded: a nested object created, a uuid kept, anything else dropped.
	 *
	 * @param mixed         $item   The item.
	 * @param CascadeTarget $target The target the created object points back to.
	 *
	 * @return array Zero or one item
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function cascadeItem(mixed $item, CascadeTarget $target): array {
		if (is_array($item) === true && $this->utilityHandler->isUuid($item) === false) {
			// A failed create keeps the item, so the list does not come out empty.
			return [$this->createRelatedObject(objectData: $item, target: $target) ?? $item];
		}

		if (is_string($item) === true && $this->utilityHandler->isUuid($item) === true) {
			return [$item];
		}

		return [];
	}//end cascadeItem()

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
			return $this->createRelatedObject(objectData: $value, target: $target) ?? $value;
		}

		return $value;
	}//end cascadeNested()

	/**
	 * Create one nested object under the target; a failure other than a refused right is logged and answers null.
	 *
	 * @param array         $objectData The nested object.
	 * @param CascadeTarget $target     The parent and the property definition.
	 *
	 * @return string|null The created uuid, or null when the create failed.
	 *
	 * @throws NotAuthorizedException When the caller may not create objects in the target schema.
	 */
	private function createUnder(array $objectData, CascadeTarget $target): ?string {
		try {
			return $this->cascadeCreate(objectData: $objectData, target: $target);
		} catch (NotAuthorizedException $e) {
			throw $e;
		} catch (Exception $e) {
			// Log error but don't expose details.
			$this->logger->error(
				message: '[CascadingHandler] Failed to create related object: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end createUnder()

	/**
	 * Create one nested object in the schema its definition references, linked
	 * back to the parent through `inversedBy`, and track it for the parent's
	 * `@self.objects`.
	 *
	 * @param array         $objectData The nested object.
	 * @param CascadeTarget $target     The parent and the property definition.
	 *
	 * @return string|null The created uuid, or null without a target schema.
	 *
	 * @throws NotAuthorizedException When the caller may not create objects in the target schema.
	 * @throws Exception When the save fails.
	 */
	private function cascadeCreate(array $objectData, CascadeTarget $target): ?string {
		$schema = $this->cascadeSchema(definition: $target->definition());
		if ($schema === null) {
			return null;
		}

		$register = null;
		if ($target->register !== null) {
			$register = (string)$target->register;
		}

		$this->saveHandler->assertMayCascadeCreate(schema: $schema, register: $register);

		// A new uuid is generated; default RBAC and multitenancy apply to the cascade.
		$createdObject = $this->saveHandler->saveObject(
			register: $target->register,
			schema: $schema,
			data: self::withoutIdentity(objectData: $this->withInverse(objectData: $objectData, target: $target)),
			_rbac: true,
			_multitenancy: true
		);

		return $this->trackCreated(createdObject: $createdObject);
	}//end cascadeCreate()

	/**
	 * The schema a `#/components/schemas/<slug>` reference names, or null.
	 *
	 * @param array $definition The property definition.
	 *
	 * @return Schema|null The referenced schema.
	 */
	private function cascadeSchema(array $definition): ?Schema {
		$schemaRef = $definition['$ref'] ?? null;
		if (in_array($schemaRef, [null, ''], true) === true || str_contains($schemaRef, '#/components/schemas/') === false) {
			return null;
		}

		$schemaSlug = substr($schemaRef, strrpos($schemaRef, '/') + 1);
		if ($schemaSlug === '') {
			return null;
		}

		return $this->findSchemaBySlug(slug: $schemaSlug);
	}//end cascadeSchema()

	/**
	 * The nested object with the parent's uuid under the definition's `inversedBy` property.
	 *
	 * @param array         $objectData The nested object.
	 * @param CascadeTarget $target     The parent and the property definition.
	 *
	 * @return array The nested object, linked back when the definition has an inverse.
	 */
	private function withInverse(array $objectData, CascadeTarget $target): array {
		$inversedBy = $target->definition()['inversedBy'] ?? null;
		if ($inversedBy !== null && $inversedBy !== '') {
			$objectData[$inversedBy] = $target->parentUuid;
		}

		return $objectData;
	}//end withInverse()

	/**
	 * Track a created nested object for the parent's `@self.objects`.
	 *
	 * @param ObjectEntity $createdObject The created object.
	 *
	 * @return string|null Its uuid.
	 */
	private function trackCreated(ObjectEntity $createdObject): ?string {
		$createdUuid = $createdObject->getUuid();
		if ($createdUuid !== null) {
			$this->saveHandler->trackCreatedSubObject($createdUuid, $createdObject->jsonSerialize());
		}

		return $createdUuid;
	}//end trackCreated()

	/**
	 * Create a related object and return its UUID.
	 *
	 * This method creates a nested object with an inverse relationship to the parent.
	 * It resolves the schema from the property definition and sets the inversedBy field.
	 *
	 * @param array         $objectData Object data to create.
	 * @param CascadeTarget $target     The parent (its uuid and register, nullable for seedData)
	 *                                  and the property definition containing the schema reference.
	 *
	 * @return string|null UUID of created object or null if creation failed.
	 *
	 * @throws NotAuthorizedException When the caller may not create objects in the target schema.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function createRelatedObject(array $objectData, CascadeTarget $target): ?string {
		return $this->createUnder(objectData: $objectData, target: $target);
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
