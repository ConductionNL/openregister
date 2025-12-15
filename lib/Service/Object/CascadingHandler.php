<?php

/**
 * CascadingHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use Psr\Log\LoggerInterface;
use Exception;

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
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class CascadingHandler
{


    /**
     * Constructor for CascadingHandler.
     *
     * @param SaveObject      $saveHandler    Handler for saving objects.
     * @param SchemaMapper    $schemaMapper   Mapper for schema entities.
     * @param UtilityHandler  $utilityHandler Handler for utility operations.
     * @param LoggerInterface $logger         Logger for logging operations.
     */
    public function __construct(
        private readonly SaveObject $saveHandler,
        private readonly SchemaMapper $schemaMapper,
        private readonly UtilityHandler $utilityHandler,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Handle pre-validation cascading for inversedBy properties.
     *
     * This method processes nested objects in inversedBy relationships before validation.
     * It automatically creates related objects and replaces them with their UUIDs.
     *
     * @param array       $object          Object data to process.
     * @param Schema      $schema          Schema entity defining the structure.
     * @param string|null $uuid            Object UUID (generated if null).
     * @param int         $currentRegister Current register ID.
     *
     * @return array Array containing [processed object, uuid].
     */
    public function handlePreValidationCascading(array $object, Schema $schema, ?string $uuid, int $currentRegister): array
    {
        // Pre-validation cascading to handle nested objects.
        try {
            // Get the URL generator from the SaveObject handler.
            $urlGenerator         = new \ReflectionClass($this->saveHandler);
            $urlGeneratorProperty = $urlGenerator->getProperty('urlGenerator');
            /*
             * @psalm-suppress UnusedMethodCall
             */
            $urlGeneratorProperty->setAccessible(true);
            $urlGeneratorInstance = $urlGeneratorProperty->getValue($this->saveHandler);

            $schemaObject = $schema->getSchemaObject($urlGeneratorInstance);
            $properties   = json_decode(json_encode($schemaObject), associative: true)['properties'] ?? [];
            // Process schema properties for inversedBy relationships.
        } catch (Exception $e) {
            // Handle error in schema processing.
            return [$object, $uuid];
        }

        // Find properties that have inversedBy configuration.
        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property.
        $inversedByProperties = array_filter(
            $properties,
            function (array $property) {
                // Check for inversedBy in array items.
                if ($property['type'] === 'array' && isset($property['items']['inversedBy']) === true) {
                    return true;
                }

                // Check for inversedBy in direct object properties.
                if (isset($property['inversedBy']) === true) {
                    return true;
                }

                return false;
            }
        );

        // Check if we have any inversedBy properties to process.
        if (count($inversedByProperties) === 0) {
            return [$object, $uuid];
        }

        // Generate UUID for parent object if not provided.
        if ($uuid === null) {
            $uuid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        }

        foreach ($inversedByProperties as $propertyName => $definition) {
            // Skip if property not present in data or is empty.
            if (isset($object[$propertyName]) === false || empty($object[$propertyName]) === true) {
                continue;
            }

            $propertyValue = $object[$propertyName];

            // Handle array properties.
            if ($definition['type'] === 'array' && isset($definition['items']['inversedBy']) === true) {
                if (is_array($propertyValue) === true && empty($propertyValue) === false) {
                    $createdUuids = [];
                    foreach ($propertyValue as $item) {
                        if (is_array($item) === true && $this->utilityHandler->isUuid($item) === false) {
                            // This is a nested object, create it first.
                            $createdUuid = $this->createRelatedObject(objectData: $item, definition: $definition['items'], parentUuid: $uuid, currentRegister: $currentRegister);

                            // If creation failed, keep original item to avoid empty array.
                            $createdUuids[] = $createdUuid ?? $item;
                        } else if (is_string($item) === true && $this->utilityHandler->isUuid($item) === true) {
                            // This is already a UUID, keep it.
                            $createdUuids[] = $item;
                        }
                    }

                    $object[$propertyName] = $createdUuids;
                }
            } else if (isset($definition['inversedBy']) === true && $definition['type'] !== 'array') {
                // Handle single object properties.
                if (is_array($propertyValue) === true && $this->utilityHandler->isUuid($propertyValue) === false) {
                    // This is a nested object, create it first.
                    $createdUuid = $this->createRelatedObject(objectData: $propertyValue, definition: $definition, parentUuid: $uuid, currentRegister: $currentRegister);

                    // Only overwrite if creation succeeded.
                    $object[$propertyName] = $createdUuid ?? $propertyValue;
                }
            }//end if
        }//end foreach

        return [$object, $uuid];

    }//end handlePreValidationCascading()


    /**
     * Create a related object and return its UUID.
     *
     * This method creates a nested object with an inverse relationship to the parent.
     * It resolves the schema from the property definition and sets the inversedBy field.
     *
     * @param array  $objectData      Object data to create.
     * @param array  $definition      Property definition containing schema reference.
     * @param string $parentUuid      UUID of the parent object.
     * @param int    $currentRegister Current register ID.
     *
     * @return string|null UUID of created object or null if creation failed.
     */
    public function createRelatedObject(array $objectData, array $definition, string $parentUuid, int $currentRegister): ?string
    {
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

            // Find the schema - use the same logic as SaveObject.resolveSchemaReference.
            $targetSchema = null;

            // First try to find by slug using findAll and filtering.
            $allSchemas = $this->schemaMapper->findAll();
            foreach ($allSchemas as $schema) {
                if (strcasecmp(string1: $schema->getSlug(), string2: $schemaSlug) === 0) {
                    $targetSchema = $schema;
                    break;
                }
            }

            if ($targetSchema === null) {
                return null;
            }

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
                data: $objectData,
                uuid: null,
                // Let it generate a new UUID.
                folderId: null,
                _rbac: true,
                // Use default RBAC for internal cascading operations.
                multi: true
                // Use default multitenancy for internal cascading operations.
            );

            return $createdObject->getUuid();
        } catch (Exception $e) {
            // Log error but don't expose details.
            $this->logger->error('Failed to create related object: '.$e->getMessage());
            return null;
        }//end try

    }//end createRelatedObject()


}//end class
