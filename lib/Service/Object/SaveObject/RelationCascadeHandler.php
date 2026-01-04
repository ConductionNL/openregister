<?php

/**
 * OpenRegister RelationCascadeHandler
 *
 * Handler for managing object relations and cascading operations.
 * Handles schema resolution, relation scanning, and cascading object creation.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
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
 */
class RelationCascadeHandler
{
    /**
     * Constructor for RelationCascadeHandler.
     *
     * @param ObjectEntityMapper $objectEntityMapper Object entity data mapper.
     * @param SchemaMapper       $schemaMapper       Schema mapper for schema operations.
     * @param RegisterMapper     $registerMapper     Register mapper for register operations.
     * @param LoggerInterface    $logger             Logger interface for logging operations.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
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
     * @param string $reference The schema reference to resolve.
     *
     * @return null|numeric-string The resolved schema ID or null if not found.
     */
    public function resolveSchemaReference(string $reference): string|null
    {
        if (empty($reference) === true) {
            return null;
        }

        // Remove query parameters if present (e.g., "schema?key=value" -> "schema").
        $cleanReference = $this->removeQueryParameters($reference);

        // First, try direct ID lookup (numeric ID or UUID).
        if (is_numeric($cleanReference) === true
            || preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $cleanReference
            ) === true
        ) {
            try {
                $schema = $this->schemaMapper->find(id: $cleanReference);
                return (string) $schema->getId();
            } catch (DoesNotExistException $e) {
                // Continue with other resolution methods.
            }
        }

        // Extract the last part of path/URL references.
        $slug = $cleanReference;
        if (str_contains($cleanReference, '/') === true) {
            // For references like "#/components/schemas/Contactgegevens" or "http://example.com/schemas/contactgegevens".
            $slug = substr($cleanReference, strrpos($cleanReference, '/') + 1);
        }

        // Try to find schema by slug (case-insensitive).
        try {
            $schemas = $this->schemaMapper->findAll();
            foreach ($schemas as $schema) {
                if (strcasecmp($schema->getSlug(), $slug) === 0) {
                    return (string) $schema->getId();
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error finding schema by slug: '.$e->getMessage());
        }

        // No match found.
        return null;
    }//end resolveSchemaReference()

    /**
     * Removes query parameters from a reference string.
     *
     * @param string $reference The reference string to clean.
     *
     * @return string The cleaned reference without query parameters.
     */
    private function removeQueryParameters(string $reference): string
    {
        if (str_contains($reference, '?') === true) {
            return substr($reference, 0, strpos($reference, '?'));
        }

        return $reference;
    }//end removeQueryParameters()

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
     */
    public function resolveRegisterReference(string $reference): string|null
    {
        if (empty($reference) === true) {
            return null;
        }

        // Remove query parameters if present.
        $cleanReference = $this->removeQueryParameters($reference);

        // First, try direct ID lookup (numeric ID or UUID).
        if (is_numeric($cleanReference) === true
            || preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $cleanReference
            ) === true
        ) {
            try {
                $register = $this->registerMapper->find(id: $cleanReference);
                return (string) $register->getId();
            } catch (DoesNotExistException $e) {
                // Continue with other resolution methods.
            }
        }

        // Extract the last part if it's a URL.
        $slug = $cleanReference;
        if (str_contains($cleanReference, '/') === true) {
            $slug = substr($cleanReference, strrpos($cleanReference, '/') + 1);
        }

        // Try slug-based lookup (case-insensitive).
        try {
            $registers = $this->registerMapper->findAll();
            foreach ($registers as $register) {
                if (strcasecmp($register->getSlug(), $slug) === 0) {
                    return (string) $register->getId();
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error finding register by slug: '.$e->getMessage());
        }

        // No match found.
        return null;
    }//end resolveRegisterReference()

    /**
     * Recursively scans for relations in data that need to be resolved.
     *
     * This method walks through the data array looking for properties that contain
     * object references (UUIDs, URLs, or numeric IDs) that need to be resolved.
     *
     * @param array       $data   The data array to scan.
     * @param string      $prefix The current property path prefix.
     * @param null|Schema $schema The schema to validate against.
     *
     * @return array Array of relation paths that need resolution.
     */
    public function scanForRelations(array $data, string $prefix='', ?Schema $schema=null): array
    {
        $relations = [];

        foreach ($data as $key => $value) {
            if ($prefix !== '') {
                $currentPath = "{$prefix}.{$key}";
            } else {
                $currentPath = $key;
            }

            // Skip if this is a special metadata field.
            if ($key === '_self' || $key === '_schema' || $key === '_register') {
                continue;
            }

            // If schema is provided, check if this property has a $ref.
            $hasRef = false;
            if ($schema !== null) {
                $properties   = $schema->getProperties();
                $propertyPath = explode('.', $currentPath);
                $propertyDef  = $this->getPropertyDefinition(properties: $properties, propertyPath: $propertyPath);

                if (isset($propertyDef['$ref']) === true) {
                    $hasRef = true;
                }
            }

            if (is_array($value) === true) {
                // If it's an array of references.
                if ($this->isArrayOfReferences($value) === true) {
                    $relations[] = $currentPath;
                } else {
                    // Recursively scan nested objects.
                    $nestedRelations = $this->scanForRelations(data: $value, prefix: $currentPath, schema: $schema);
                    $relations       = array_merge($relations, $nestedRelations);
                }
            } else if (is_string($value) === true && $this->isReference($value) === true) {
                // Single reference value.
                if ($hasRef === true || $this->looksLikeObjectReference($value) === true) {
                    $relations[] = $currentPath;
                }
            }
        }//end foreach

        return $relations;
    }//end scanForRelations()

    /**
     * Gets a property definition from properties array by path.
     *
     * @param array $properties   The properties array.
     * @param array $propertyPath The property path parts.
     *
     * @return array The property definition or empty array.
     */
    private function getPropertyDefinition(array $properties, array $propertyPath): array
    {
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
     */
    private function isArrayOfReferences(array $array): bool
    {
        foreach ($array as $item) {
            if (is_string($item) === true && $this->isReference($item) === true) {
                return true;
            }
        }

        return false;
    }//end isArrayOfReferences()

    /**
     * Checks if a value looks like an object reference.
     *
     * @param string $value The value to check.
     *
     * @return bool True if it looks like a reference.
     */
    private function looksLikeObjectReference(string $value): bool
    {
        // UUID pattern.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === true) {
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
     * - A UUID string
     * - A URL containing /objects/ or /api/
     * - A numeric ID (if > 0)
     *
     * @param string $value The value to check.
     *
     * @return bool True if value is a reference.
     */
    public function isReference(string $value): bool
    {
        // Check for UUID format.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1) {
            return true;
        }

        // Check for URL patterns.
        if (str_contains($value, '/objects/') === true
            || str_contains($value, '/api/') === true
        ) {
            return true;
        }

        // Check for numeric ID.
        if (is_numeric($value) === true && (int) $value > 0) {
            return true;
        }

        return false;
    }//end isReference()

    /**
     * Updates object relations by resolving references to actual object UUIDs.
     *
     * This method walks through the object data and replaces references
     * (URLs, IDs) with the corresponding object UUIDs.
     *
     * @param ObjectEntity $objectEntity The object entity being updated.
     * @param array        $data         The object data containing relations.
     * @param null|Schema  $schema       The schema for validation.
     *
     * @return ObjectEntity The updated object entity.
     */
    public function updateObjectRelations(ObjectEntity $objectEntity, array $data, ?Schema $schema=null): ObjectEntity
    {
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
     * @param array  $objectData   The object data (passed by reference).
     * @param string $relationPath The dot-notation path to the relation.
     *
     * @return void
     */
    private function resolveRelationPath(array &$objectData, string $relationPath): void
    {
        $parts   = explode('.', $relationPath);
        $current = &$objectData;

        // Navigate to the parent of the target property.
        for ($i = 0; $i < count($parts) - 1; $i++) {
            if (isset($current[$parts[$i]]) === false) {
                return;
            }

            $current = &$current[$parts[$i]];
        }

        $lastKey = $parts[count($parts) - 1];

        if (isset($current[$lastKey]) === false) {
            return;
        }

        $value = $current[$lastKey];

        // Resolve the reference.
        if (is_array($value) === true) {
            // Array of references.
            $resolved = [];
            foreach ($value as $ref) {
                if (is_string($ref) === true) {
                    $uuid = $this->extractUuidFromReference($ref);
                    if ($uuid !== null) {
                        $resolved[] = $uuid;
                    }
                }
            }

            $current[$lastKey] = $resolved;
        } else if (is_string($value) === true) {
            // Single reference.
            $uuid = $this->extractUuidFromReference($value);
            if ($uuid !== null) {
                $current[$lastKey] = $uuid;
            }
        }
    }//end resolveRelationPath()

    /**
     * Extracts UUID from a reference string.
     *
     * @param string $reference The reference string.
     *
     * @return string|null The extracted UUID or null.
     */
    private function extractUuidFromReference(string $reference): ?string
    {
        // Already a UUID.
        if (Uuid::isValid($reference) === true) {
            return $reference;
        }

        // Try to find object by ID.
        if (is_numeric($reference) === true) {
            try {
                $object = $this->objectEntityMapper->find((int) $reference);
                return $object->getUuid();
            } catch (DoesNotExistException $e) {
                return null;
            }
        }

        // Extract from URL.
        if (str_contains($reference, '/objects/') === true) {
            $parts = explode('/objects/', $reference);
            if (count($parts) === 2) {
                $uuid = trim($parts[1], '/');
                if (Uuid::isValid($uuid) === true) {
                    return $uuid;
                }
            }
        }

        return null;
    }//end extractUuidFromReference()

    /**
     * Cascades object creation for inversedBy properties.
     *
     * This method handles the creation of related objects before the main object
     * is validated and saved (pre-validation cascading).
     *
     * @param ObjectEntity $objectEntity The parent object entity.
     * @param Schema       $schema       The schema of the parent object.
     * @param array        $data         The object data containing nested objects.
     *
     * @return array The updated data with created object UUIDs.
     */
    public function cascadeObjects(ObjectEntity $objectEntity, Schema $schema, array $data): array
    {
        $properties = $schema->getProperties();

        foreach ($properties as $propertyName => $property) {
            if (isset($property['inversedBy']) === false || isset($data[$propertyName]) === false) {
                continue;
            }

            // Check if property data is an array of objects or a single object.
            $propData = $data[$propertyName];

            if (empty($propData) === true) {
                continue;
            }

            // Handle array of objects.
            if (isset($property['type']) === true && $property['type'] === 'array') {
                $data[$propertyName] = $this->cascadeMultipleObjects(objectEntity: $objectEntity, property: $property, propData: $propData);
            } else {
                // Handle single object.
                if (is_array($propData) === true && $this->isArrayOfScalars($propData) === false) {
                    $uuid = $this->cascadeSingleObject(objectEntity: $objectEntity, definition: $property, object: $propData);
                    if ($uuid !== null) {
                        $data[$propertyName] = $uuid;
                    }
                }
            }
        }//end foreach

        return $data;
    }//end cascadeObjects()

    /**
     * Checks if array contains only scalar values.
     *
     * @param array $array The array to check.
     *
     * @return bool True if all values are scalar.
     */
    private function isArrayOfScalars(array $array): bool
    {
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
     * @param array        $property     The property definition.
     * @param array        $propData     The property data (array of objects).
     *
     * @return string[] Array of created object UUIDs.
     *
     * @psalm-return list<string>
     */
    public function cascadeMultipleObjects(ObjectEntity $objectEntity, array $property, array $propData): array
    {
        $createdUuids = [];

        foreach ($propData as $object) {
            if (is_array($object) === true && $this->isArrayOfScalars($object) === false) {
                $uuid = $this->cascadeSingleObject(objectEntity: $objectEntity, definition: $property, object: $object);
                if ($uuid !== null) {
                    $createdUuids[] = $uuid;
                }
            } else if (is_string($object) === true && Uuid::isValid($object) === true) {
                // Already a UUID reference.
                $createdUuids[] = $object;
            }
        }

        return $createdUuids;
    }//end cascadeMultipleObjects()

    /**
     * Cascades creation of a single related object.
     *
     * @param ObjectEntity $objectEntity The parent object entity.
     * @param array        $definition   The property definition containing $ref and inversedBy.
     * @param array        $object       The nested object data to create.
     *
     * @return null The UUID of the created object or null.
     */
    public function cascadeSingleObject(ObjectEntity $objectEntity, array $definition, array $object)
    {
        // TODO: Implement actual cascading logic.
        // This requires access to ObjectService which would create circular dependency.
        // Need to refactor this to use event system or separate coordination service.
        $this->logger->warning('Cascade object creation not yet implemented in extracted handler');

        return null;
    }//end cascadeSingleObject()

    /**
     * Handles inverse relation write-back operations.
     *
     * After an object is saved, this method updates related objects to maintain
     * bidirectional relationship integrity (inversedBy properties).
     *
     * @param ObjectEntity $objectEntity The saved object entity.
     * @param Schema       $schema       The schema of the object.
     * @param array        $data         The object data.
     *
     * @return array The updated data after write-back operations.
     */
    public function handleInverseRelationsWriteBack(ObjectEntity $objectEntity, Schema $schema, array $data): array
    {
        // TODO: Implement inverse relation write-back.
        // This requires access to ObjectService which would create circular dependency.
        // Need to refactor this to use event system or separate coordination service.
        $this->logger->warning('Inverse relation write-back not yet implemented in extracted handler');

        return $data;
    }//end handleInverseRelationsWriteBack()
}//end class
