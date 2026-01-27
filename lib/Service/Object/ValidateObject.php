<?php

/**
 * OpenRegister ValidateObject Handler
 *
 * Handler class responsible for validating objects against their schemas.
 * This handler provides methods for:
 * - JSON Schema validation of objects
 * - Custom validation rule processing
 * - Schema resolution and caching
 * - Validation error handling and formatting
 * - Support for external schema references
 * - Format validation (e.g., BSN numbers)
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Object;

use stdClass;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Formats\BsnFormat;
use OCA\OpenRegister\Formats\SemVerFormat;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use Opis\Uri\Uri;
use Psr\Log\LoggerInterface;

/**
 * Handler class for validating objects in the OpenRegister application.
 *
 * This handler is responsible for validating objects against their schemas,
 * including custom validation rules and error handling.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Objects
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   GIT: <git_id>
 * @copyright 2024 Conduction b.v.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Validation requires comprehensive rule handling
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex JSON Schema validation logic
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Validation requires multiple format and schema dependencies
 */

class ValidateObject
{
    /**
     * Default validation error message.
     *
     * @var string
     */
    public const VALIDATION_ERROR_MESSAGE = 'Invalid object';

    /**
     * Constructor for ValidateObject
     *
     * @param IAppConfig         $config       Configuration service.
     * @param ObjectEntityMapper $objectMapper Object mapper.
     * @param SchemaMapper       $schemaMapper Schema mapper.
     * @param IURLGenerator      $urlGenerator URL generator.
     * @param LoggerInterface    $logger       Logger for logging operations.
     */
    public function __construct(
        private IAppConfig $config,
        private ObjectEntityMapper $objectMapper,
        private SchemaMapper $schemaMapper,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Pre-processes a schema object to resolve all schema references.
     *
     * This method recursively walks through the schema object and replaces
     * any "#/components/schemas/[slug]" references with the actual schema definitions.
     * This ensures the validation library can work with fully resolved schemas.
     *
     * @param object $schemaObject         The schema object to process
     * @param array  $visited              Array to track visited schemas to prevent infinite loops
     * @param bool   $_skipUuidTransformed Whether to skip UUID transformation (unused)
     *
     * @return object The processed schema object with resolved references
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Schema reference resolution requires multiple type checks
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  Boolean flag needed for backward compatibility
     */
    private function preprocessSchemaReferences(
        object $schemaObject,
        array $visited=[],
        bool $_skipUuidTransformed=false
    ): object {
        // Clone the schema object to avoid modifying the original.
        $processedSchema = json_decode(json_encode($schemaObject));

        // Recursively process all properties.
        if (($processedSchema->properties ?? null) !== null) {
            foreach ($processedSchema->properties as $propertyName => $propertySchema) {
                // Skip processing if this property has been transformed to a UUID type by OpenRegister logic.
                // This prevents circular references for related-object properties.
                $isStringType   = ($propertySchema->type ?? null) !== null
                    && $propertySchema->type === 'string';
                $hasUuidPattern = ($propertySchema->pattern ?? null) !== null
                    && str_contains($propertySchema->pattern, 'uuid') === true;
                if ($isStringType === true && $hasUuidPattern === true) {
                    continue;
                }

                $processedSchema->properties->$propertyName = $this->resolveSchemaProperty(
                    propertySchema: $propertySchema,
                    visited: $visited
                );
            }
        }

        // Process array items if present.
        if (($processedSchema->items ?? null) !== null) {
            // Skip processing if array items have been transformed to UUID type by OpenRegister logic.
            $isStringType         = ($processedSchema->items->type ?? null) !== null
                && $processedSchema->items->type === 'string';
            $hasUuidPattern       = ($processedSchema->items->pattern ?? null) !== null
                && str_contains($processedSchema->items->pattern, 'uuid') === true;
            $isAlreadyTransformed = $isStringType && $hasUuidPattern;

            if ($isAlreadyTransformed === false) {
                $processedSchema->items = $this->resolveSchemaProperty(
                    propertySchema: $processedSchema->items,
                    visited: $visited
                );
            }
        }

        return $processedSchema;
    }//end preprocessSchemaReferences()

    /**
     * Resolves schema references in a property definition.
     *
     * @param object $propertySchema The property schema to resolve
     * @param array  $visited        Array to track visited schemas to prevent infinite loops
     *
     * @return object The resolved property schema
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex reference resolution with multiple format handlers
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple reference types and nested schema scenarios
     */
    private function resolveSchemaProperty(object $propertySchema, array $visited=[]): object
    {
        // Handle $ref references.
        if (($propertySchema->{'$ref'} ?? null) !== null) {
            $reference = $propertySchema->{'$ref'};

            // Handle both string and object formats for $ref.
            if (is_object($reference) === true && (($reference->id ?? null) !== null)) {
                $reference = $reference->id;
            } else if (is_array($reference) === true && (($reference['id'] ?? null) !== null)) {
                $reference = $reference['id'];
            }

            // Check if this is a schema reference we should resolve.
            if (is_string($reference) === true && str_contains($reference, '#/components/schemas/') === true) {
                // Remove query parameters if present.
                $cleanReference = $this->removeQueryParameters($reference);
                $schemaSlug     = substr($cleanReference, strrpos($cleanReference, '/') + 1);

                // Prevent infinite loops.
                if (in_array($schemaSlug, $visited) === true) {
                    return $propertySchema;
                }

                // Try to resolve the schema.
                $referencedSchema = $this->findSchemaBySlug($schemaSlug);
                if ($referencedSchema !== null) {
                    // Get the referenced schema object and recursively process it.
                    $refSchemaObj = $referencedSchema->getSchemaObject($this->urlGenerator);

                    $newVisited     = array_merge($visited, [$schemaSlug]);
                    $resolvedSchema = $this->preprocessSchemaReferences(
                        schemaObject: $refSchemaObj,
                        visited: $newVisited
                    );

                    // For object properties, we need to handle both nested objects and UUID references.
                    if (($propertySchema->type ?? null) !== null && $propertySchema->type === 'object') {
                        // Create a union type that allows both the full object and a UUID string.
                        $unionSchema        = new stdClass();
                        $unionSchema->oneOf = [
                            $resolvedSchema,
                        // Full object.
                            (object) [
                        // UUID string.
                                'type'    => 'string',
                                'pattern' => '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$',
                            ],
                        ];

                        // Copy any other properties from the original schema.
                        foreach ($propertySchema as $key => $value) {
                            if ($key !== '$ref' && $key !== 'type') {
                                $unionSchema->$key = $value;
                            }
                        }

                        return $unionSchema;
                    }//end if

                    // For non-object properties, just return the resolved schema.
                    // But preserve any additional properties from the original.
                    foreach ($propertySchema as $key => $value) {
                        if ($key !== '$ref') {
                            $resolvedSchema->$key = $value;
                        }
                    }

                    return $resolvedSchema;
                }//end if
            }//end if
        }//end if

        // Handle array items with $ref.
        if (($propertySchema->items ?? null) !== null && (($propertySchema->items->{'$ref'} ?? null) !== null) === true) {
            $propertySchema->items = $this->resolveSchemaProperty(propertySchema: $propertySchema->items, visited: $visited);
        }

        // Recursively process nested properties.
        if (($propertySchema->properties ?? null) !== null) {
            foreach ($propertySchema->properties ?? [] as $nestedPropertyName => $nestedPropertySchema) {
                $propertySchema->properties->$nestedPropertyName = $this->resolveSchemaProperty(
                    propertySchema: $nestedPropertySchema,
                    visited: $visited
                );
            }
        }

        return $propertySchema;
    }//end resolveSchemaProperty()

    /**
     * Transforms OpenRegister-specific object configurations before validation.
     *
     * This method handles the difference between:
     * - Related objects: Should expect UUID strings, not full objects
     * - Nested objects: Should expect full object structures
     *
     * This prevents circular reference issues and ensures proper validation
     * according to OpenRegister's object handling logic.
     *
     * @param object $schemaObject The schema object to transform
     *
     * @return object The transformed schema object
     */
    private function transformOpenRegisterObjectConfigurations(object $schemaObject): object
    {
        if (isset($schemaObject->properties) === false) {
            return $schemaObject;
        }

        foreach ($schemaObject->properties as $propertyName => $propertySchema) {
            // Suppress unused variable warning for $propertyName - only processing schemas.
            unset($propertyName);
            $this->transformPropertyForOpenRegister($propertySchema);
        }

        return $schemaObject;
    }//end transformOpenRegisterObjectConfigurations()

    /**
     * Transforms a single property based on OpenRegister object configuration.
     *
     * TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property
     *
     * @param object $propertySchema The property schema to transform
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple OpenRegister configuration scenarios
     * @SuppressWarnings(PHPMD.NPathComplexity)      Various property transformation paths
     */
    private function transformPropertyForOpenRegister(object $propertySchema): void
    {
        // Handle inversedBy relationships for validation.
        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items to config.
        if (($propertySchema->inversedBy ?? null) !== null && $propertySchema->inversedBy !== '') {
            // Check if this is an array property.
            $isArrayType = ($propertySchema->type ?? null) !== null
                && $propertySchema->type === 'array';
            if ($isArrayType === true) {
                // For inversedBy array properties, allow objects or UUIDs
                // (pre-validation cascading will handle transformation).
                $propertySchema->items = (object) [
                    'oneOf' => [
                        (object) [
                            'type'        => 'string',
                            'pattern'     => '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$',
                            'description' => 'UUID reference to a related object',
                        ],
                        (object) [
                            'type'        => 'object',
                            'description' => 'Nested object that will be created separately',
                        ],
                    ],
                ];
            } else if (($propertySchema->type ?? null) !== null
                && $propertySchema->type === 'object'
            ) {
                // For inversedBy object properties, allow objects, UUIDs, or null
                // (pre-validation cascading will handle transformation).
                $propertySchema->oneOf = [
                    (object) [
                        'type'        => 'null',
                        'description' => 'No related object (inversedBy - managed by other side)',
                    ],
                    (object) [
                        'type'        => 'string',
                        'pattern'     => '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$',
                        'description' => 'UUID reference to a related object',
                    ],
                    (object) [
                        'type'        => 'object',
                        'description' => 'Nested object that will be created separately',
                    ],
                ];
                unset(
                    $propertySchema->type,
                    $propertySchema->pattern,
                    $propertySchema->properties,
                    $propertySchema->required,
                    $propertySchema->{'$ref'}
                );
            }//end if
        }//end if

        // Handle array properties with object items.
        $isArrayType = ($propertySchema->type ?? null) !== null
            && $propertySchema->type === 'array';
        $hasItems    = ($propertySchema->items ?? null) !== null;
        if ($isArrayType === true && $hasItems === true) {
            $this->transformArrayItemsForOpenRegister($propertySchema->items);
        }

        // Handle direct object properties.
        if (($propertySchema->type ?? null) !== null && $propertySchema->type === 'object') {
            $this->transformObjectPropertyForOpenRegister($propertySchema);
        }

        // Recursively transform nested properties.
        if (($propertySchema->properties ?? null) !== null) {
            foreach ($propertySchema->properties ?? [] as $nestedPropertyName => $nestedPropertySchema) {
                // Suppress unused variable warning for $nestedPropertyName - only processing schemas.
                unset($nestedPropertyName);
                $this->transformPropertyForOpenRegister($nestedPropertySchema);
            }
        }
    }//end transformPropertyForOpenRegister()

    /**
     * Transforms array items based on OpenRegister object configuration.
     *
     * @param mixed $itemsSchema The array items schema to transform
     *
     * @return void
     */
    private function transformArrayItemsForOpenRegister($itemsSchema): void
    {
        // Handle case where items might be an array or not an object.
        if (is_object($itemsSchema) === false) {
            return;
        }

        // Handle inversedBy relationships for array items.
        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items to config.
        if (($itemsSchema->inversedBy ?? null) !== null) {
            // For inversedBy array items, transform to UUID string validation.
            // But since this is an inversedBy relationship, the parent array should be empty.
            // The transformation is handled at the parent array level.
            $itemsSchema->type        = 'string';
            $itemsSchema->pattern     = '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$';
            $itemsSchema->description = 'UUID reference to a related object (inversedBy - should be empty)';
            unset($itemsSchema->properties, $itemsSchema->required, $itemsSchema->{'$ref'});
            return;
        }

        if (isset($itemsSchema->type) === false || $itemsSchema->type !== 'object') {
            return;
        }

        $this->transformObjectPropertyForOpenRegister($itemsSchema);
    }//end transformArrayItemsForOpenRegister()

    /**
     * Transforms object properties based on OpenRegister object configuration.
     *
     * @param object $objectSchema The object schema to transform
     *
     * @return void
     */
    private function transformObjectPropertyForOpenRegister(object $objectSchema): void
    {
        // Check if this has objectConfiguration (can be array or object).
        // Also check inside items.oneOf for polymorphic references.
        $handling = $this->extractObjectConfigurationHandling($objectSchema);

        if ($handling === null) {
            return;
        }

        switch ($handling) {
            case 'related-object':
                // For related objects, expect UUID strings instead of full objects.
                $this->transformToUuidProperty($objectSchema);
                break;

            case 'nested-object':
                // For nested objects, keep the full object structure but remove circular refs.
                $this->transformToNestedObjectProperty($objectSchema);
                break;

            default:
                // For other handling types, leave as-is.
                break;
        }
    }//end transformObjectPropertyForOpenRegister()

    /**
     * Transforms an object property to expect UUID strings for related objects.
     *
     * @param object $objectSchema The object schema to transform
     *
     * @return void
     */
    private function transformToUuidProperty(object $objectSchema): void
    {
        // If this property has inversedBy, it should support both objects and UUID strings.
        if (($objectSchema->inversedBy ?? null) === null) {
            // Original behavior for non-inversedBy properties.
            // Remove object-specific properties.
            unset($objectSchema->properties, $objectSchema->required);

            // Set to string type with UUID pattern.
            $objectSchema->type        = 'string';
            $objectSchema->pattern     = '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$';
            $objectSchema->description = 'UUID reference to a related object';

            // Remove $ref to prevent circular references.
            unset($objectSchema->{'$ref'});
            return;
        }

        // Create a union type that allows both full objects and UUID strings.
        $originalProperties = $objectSchema->properties ?? null;
        $originalRequired   = $objectSchema->required ?? null;
        $originalRef        = $objectSchema->{'$ref'} ?? null;

        // Create the object schema (preserve original structure).
        $objectTypeSchema = (object) [
            'type' => 'object',
        ];

        if ($originalProperties !== null && empty($originalProperties) === false) {
            $objectTypeSchema->properties = $originalProperties;
        }

        if ($originalRequired !== null && empty($originalRequired) === false) {
            $objectTypeSchema->required = $originalRequired;
        }

        if ($originalRef !== null && $originalRef !== '') {
            $objectTypeSchema->{'$ref'} = $originalRef;
        }

        // Create the UUID string schema.
        $uuidTypeSchema = (object) [
            'type'        => 'string',
            'pattern'     => '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$',
            'description' => 'UUID reference to a related object',
        ];

        // Clear the current object and set up union type.
        $objectSchema->type = null;
        unset($objectSchema->properties, $objectSchema->required, $objectSchema->{'$ref'});

        // Create union type.
        $objectSchema->oneOf = [
            $objectTypeSchema,
            $uuidTypeSchema,
        ];

        $objectSchema->description = 'Related object (can be full object or UUID reference)';
        // End if.
    }//end transformToUuidProperty()

    /**
     * Transforms an object property for nested objects, removing circular references.
     *
     * @param object $objectSchema The object schema to transform
     *
     * @return void
     */
    private function transformToNestedObjectProperty(object $objectSchema): void
    {
        // For nested objects, we need to resolve the $ref but prevent circular references.
        if (($objectSchema->{'$ref'} ?? null) !== null) {
            $ref = $objectSchema->{'$ref'};

            // Handle both string and object formats for $ref.
            $reference = $ref;
            if (is_object($ref) === true && (($ref->id ?? null) !== null)) {
                $reference = $ref->id;
            } else if (is_array($ref) === true && (($ref['id'] ?? null) !== null)) {
                $reference = $ref['id'];
            }

            // If this is a self-reference (circular), convert to a simple object type.
            if (is_string($reference) === true && str_contains($reference, '/components/schemas/') === true) {
                // Remove query parameters if present.
                $cleanReference = $this->removeQueryParameters($reference);
                $schemaSlug     = substr($cleanReference, strrpos($cleanReference, '/') + 1);

                // For self-references, create a generic object structure to prevent circular validation.
                // Create a temporary object for isSelfReference check.
                $tempSchema = (object) ['$ref' => $schemaSlug];
                if ($this->isSelfReference(propertySchema: $tempSchema, schemaSlug: $schemaSlug) === true) {
                    $objectSchema->type        = 'object';
                    $objectSchema->description = 'Nested object (self-reference prevented)';
                    unset($objectSchema->{'$ref'});

                    // Add basic properties that most objects should have.
                    $objectSchema->properties = (object) [
                        'id' => (object) [
                            'type'        => 'string',
                            'description' => 'Object identifier',
                        ],
                    ];
                }
            }//end if
        }//end if
    }//end transformToNestedObjectProperty()

    /**
     * Extracts the objectConfiguration handling value from a property schema.
     *
     * Checks for objectConfiguration in multiple locations:
     * - Directly on the property schema
     * - Inside items (for array-like structures)
     * - Inside items.oneOf (for polymorphic references)
     *
     * @param object $propertySchema The property schema to check
     *
     * @return string|null The handling value or null if not found
     */
    private function extractObjectConfigurationHandling(object $propertySchema): ?string
    {
        // Check directly on the property schema.
        if (isset($propertySchema->objectConfiguration)) {
            $handling = $this->getHandlingFromConfig($propertySchema->objectConfiguration);
            if ($handling !== null) {
                return $handling;
            }
        }

        // Check inside items (for properties with items structure).
        // Items can be either an object (stdClass) or an array depending on how the schema was processed.
        if (isset($propertySchema->items)) {
            $items = $propertySchema->items;

            // Check if items has objectConfiguration directly.
            $itemsConfig = $this->getNestedValue($items, 'objectConfiguration');
            if ($itemsConfig !== null) {
                $handling = $this->getHandlingFromConfig($itemsConfig);
                if ($handling !== null) {
                    return $handling;
                }
            }

            // Check inside items.oneOf (for polymorphic references).
            $oneOf = $this->getNestedValue($items, 'oneOf');
            if ($oneOf !== null && (is_array($oneOf) || is_object($oneOf))) {
                foreach ($oneOf as $oneOfItem) {
                    $oneOfConfig = $this->getNestedValue($oneOfItem, 'objectConfiguration');
                    if ($oneOfConfig !== null) {
                        $handling = $this->getHandlingFromConfig($oneOfConfig);
                        if ($handling !== null) {
                            return $handling;
                        }
                    }
                }
            }
        }

        // Check inside oneOf directly on the property (alternative structure).
        if (isset($propertySchema->oneOf) && (is_array($propertySchema->oneOf) || is_object($propertySchema->oneOf))) {
            foreach ($propertySchema->oneOf as $oneOfItem) {
                $oneOfConfig = $this->getNestedValue($oneOfItem, 'objectConfiguration');
                if ($oneOfConfig !== null) {
                    $handling = $this->getHandlingFromConfig($oneOfConfig);
                    if ($handling !== null) {
                        return $handling;
                    }
                }
            }
        }

        return null;
    }//end extractObjectConfigurationHandling()

    /**
     * Gets the handling value from an objectConfiguration.
     *
     * @param mixed $config The objectConfiguration (array or object)
     *
     * @return string|null The handling value or null
     */
    private function getHandlingFromConfig($config): ?string
    {
        if (is_array($config) && isset($config['handling'])) {
            return $config['handling'];
        }

        if (is_object($config) && isset($config->handling)) {
            return $config->handling;
        }

        return null;
    }//end getHandlingFromConfig()

    /**
     * Gets a nested value from either an array or object.
     *
     * @param mixed  $data The data structure (array or object)
     * @param string $key  The key to retrieve
     *
     * @return mixed The value or null if not found
     */
    private function getNestedValue($data, string $key)
    {
        if (is_array($data) && isset($data[$key])) {
            return $data[$key];
        }

        if (is_object($data) && isset($data->$key)) {
            return $data->$key;
        }

        return null;
    }//end getNestedValue()

    /**
     * Transforms schema for validation by handling circular references, OpenRegister configurations, and schema resolution.
     *
     * This function combines all schema transformation steps into a single method:
     * 1. Detects and transforms circular references (self-references)
     * 2. Transforms OpenRegister-specific object configurations
     * 3. Resolves schema references
     *
     * @param object $schemaObject      The schema object to transform
     * @param array  $object            The object data to transform
     * @param string $currentSchemaSlug The current schema slug to detect self-references
     *
     * @return (array|object)[] Array containing [transformedSchema, transformedObject]
     *
     * @psalm-return list{object, array}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex schema transformation with multiple scenarios
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive schema transformation logic
     */
    private function transformSchemaForValidation(object $schemaObject, array $object, string $currentSchemaSlug): array
    {

        if (isset($schemaObject->properties) === false) {
            return [$schemaObject, $object];
        }

        $propertiesArray = (array) $schemaObject->properties;
        // Step 1: Handle circular references.
        foreach ($propertiesArray as $propertyName => $propertySchema) {
            // Suppress unused variable warning for $propertyName - only processing schemas.
            unset($propertyName);
            // Check if this property has a $ref that references the current schema.
            if ($this->isSelfReference(propertySchema: $propertySchema, schemaSlug: $currentSchemaSlug) === true) {
                // Check if this is a related-object with objectConfiguration.
                // Handle both array and object formats for objectConfiguration
                $config = $propertySchema->objectConfiguration ?? null;
                $handling = null;
                if (is_array($config) && isset($config['handling'])) {
                    $handling = $config['handling'];
                } elseif (is_object($config) && isset($config->handling)) {
                    $handling = $config->handling;
                }
                if ($config !== null && $handling === 'related-object') {
                    // Handle inversedBy relationships for single objects.
                    if (($propertySchema->inversedBy ?? null) !== null) {
                        // For inversedBy properties, allow objects, UUIDs, or null
                        // (pre-validation cascading will handle transformation).
                        $propertySchema->oneOf = [
                            (object) [
                                'type'        => 'null',
                                'description' => 'No related object (inversedBy - managed by other side)',
                            ],
                            (object) [
                                'type'        => 'string',
                                'pattern'     => '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$',
                                'description' => 'UUID reference to a related object',
                            ],
                            (object) [
                                'type'        => 'object',
                                'description' => 'Nested object that will be created separately',
                            ],
                        ];
                        unset($propertySchema->type, $propertySchema->pattern);
                    }

                    if (($propertySchema->inversedBy ?? null) === null) {
                        // For non-inversedBy properties, expect string UUID.
                        $uuidPattern          = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-';
                        $uuidPattern         .= '[0-9a-f]{4}-[0-9a-f]{12}$';
                        // Note: For related-object patterns, we support prefixed UUIDs, UUIDs without dashes, and numeric IDs
                        $uuidPattern = '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$';
                        $propertySchema->type = 'string';
                        $propertySchema->pattern = $uuidPattern;
                        $desc = 'UUID reference to a related object (self-reference)';
                        $propertySchema->description = $desc;
                    }//end if

                    unset($propertySchema->properties, $propertySchema->required, $propertySchema->{'$ref'});
                } else if (($propertySchema->type ?? null) !== null
                    && $propertySchema->type === 'array'
                    && (($propertySchema->items ?? null) !== null) === true
                    && is_object($propertySchema->items) === true
                    && $this->isSelfReference(
                        propertySchema: $propertySchema->items,
                        schemaSlug: $currentSchemaSlug
                    ) === true
                ) {
                    // Check if array items are self-referencing.
                    $propertySchema->type = 'array';

                    // Handle inversedBy relationships differently for validation.
                    if (($propertySchema->items->inversedBy ?? null) !== null) {
                        // For inversedBy properties, allow objects or UUIDs
                        // (pre-validation cascading will handle transformation).
                        $propertySchema->type  = 'array';
                        $propertySchema->items = (object) [
                            'oneOf' => [
                                (object) [
                                    'type'        => 'string',
                                    'pattern'     => '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$',
                                    'description' => 'UUID reference to a related object',
                                ],
                                (object) [
                                    'type'        => 'object',
                                    'description' => 'Nested object that will be created separately',
                                ],
                            ],
                        ];
                    }

                    if (($propertySchema->items->inversedBy ?? null) === null) {
                        // For non-inversedBy properties, expect array of UUIDs.
                        $propertySchema->items = (object) [
                            'type'        => 'string',
                            'pattern'     => '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$',
                            'description' => 'UUID reference to a related object (self-reference)',
                        ];
                    }//end if

                    unset($propertySchema->{'$ref'});

                    // Ensure items has a valid schema after transformation.
                    if (isset($propertySchema->items->type) === false && isset($propertySchema->items->oneOf) === false) {
                        $propertySchema->items->type = 'string';
                    }
                }//end if

                // Remove the $ref to prevent circular validation issues.
                unset($propertySchema->{'$ref'});
            }//end if
        }//end foreach

        // Step 2: Transform OpenRegister-specific object configurations.
        $schemaObject = $this->transformOpenRegisterObjectConfigurations($schemaObject);

        // Step 3: Remove $id property to prevent duplicate schema ID errors.
        if (($schemaObject->{'$id'} ?? null) !== null) {
            unset($schemaObject->{'$id'});
        }

        // Step 4: Pre-process the schema to resolve all schema references (but skip UUID-transformed properties).
        // Temporarily disable schema resolution to see if that's causing the duplicate schema ID issue.
        // $schemaObject = $this->preprocessSchemaReferences($schemaObject, [], true);.
        return [$schemaObject, $object];
    }//end transformSchemaForValidation()

    /**
     * Cleans a schema object by removing all Nextcloud-specific metadata properties.
     * This ensures the schema is valid JSON Schema before validation.
     *
     * @param object $schemaObject  The schema object to clean
     * @param bool   $_isArrayItems Whether this is cleaning array items (more aggressive cleaning)
     *
     * @return object The cleaned schema object
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flag needed to handle array items differently
     */
    private function cleanSchemaForValidation(object $schemaObject, bool $_isArrayItems=false): object
    {

        // Clone the schema object to avoid modifying the original.
        $cleanedSchema = json_decode(json_encode($schemaObject));

        // Remove Nextcloud-specific metadata properties.
        $metadataProperties = [
            'cascadeDelete',
            'objectConfiguration',
            'inversedBy',
            'mappedBy',
            'targetEntity',
            'fetch',
            'indexBy',
            'orphanRemoval',
            'joinColumns',
            'inverseJoinColumns',
            'joinTable',
            'uniqueConstraints',
            'indexes',
            'options',
        ];

        foreach ($metadataProperties as $property) {
            if (($cleanedSchema->$property ?? null) !== null) {
                unset($cleanedSchema->$property);
            }
        }

        // Handle properties recursively.
        if (($cleanedSchema->properties ?? null) !== null) {
            foreach ($cleanedSchema->properties as $propertyName => $propertySchema) {
                $cleanedSchema->properties->$propertyName = $this->cleanPropertyForValidation(
                    propertySchema: $propertySchema,
                    isArrayItems: false
                );
            }
        }

        // Handle array items - this is where the distinction matters.
        if (($cleanedSchema->items ?? null) !== null) {
            $cleanedSchema->items = $this->cleanPropertyForValidation(
                propertySchema: $cleanedSchema->items,
                isArrayItems: true
            );
        }

        return $cleanedSchema;
    }//end cleanSchemaForValidation()

    /**
     * Cleans a property schema by removing metadata and handling special cases.
     *
     * @param mixed $propertySchema The property schema to clean
     * @param bool  $isArrayItems   Whether this is cleaning array items (more aggressive)
     *
     * @return mixed The cleaned property schema
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flag needed to handle array items differently
     */
    private function cleanPropertyForValidation($propertySchema, bool $isArrayItems=false)
    {
        // Handle non-object properties.
        if (is_object($propertySchema) === false) {
            return $propertySchema;
        }

        // Clone to avoid modifying original.
        $cleanedProperty = json_decode(json_encode($propertySchema));

        // Remove Nextcloud-specific metadata properties.
        $metadataProperties = [
            'cascadeDelete',
            'objectConfiguration',
            'inversedBy',
            'mappedBy',
            'targetEntity',
            'fetch',
            'indexBy',
            'orphanRemoval',
            'joinColumns',
            'inverseJoinColumns',
            'joinTable',
            'uniqueConstraints',
            'indexes',
            'options',
        ];

        foreach ($metadataProperties as $property) {
            if (($cleanedProperty->$property ?? null) !== null) {
                unset($cleanedProperty->$property);
            }
        }

        // Transform custom OpenRegister types to valid JSON Schema types.
        // JSON Schema only allows: string, number, integer, boolean, array, object, null.
        $cleanedProperty = $this->transformCustomTypeToJsonSchemaType($cleanedProperty);

        // Special handling for array items - more aggressive transformation.
        if ($isArrayItems === true) {
            return $this->transformArrayItemsForValidation($cleanedProperty);
        }

        // Handle nested properties recursively.
        if (($cleanedProperty->properties ?? null) !== null) {
            foreach ($cleanedProperty->properties as $nestedPropertyName => $nestedPropertySchema) {
                $cleanedProperty->properties->$nestedPropertyName = $this->cleanPropertyForValidation(
                    propertySchema: $nestedPropertySchema,
                    isArrayItems: false
                );
            }
        }

        // Handle nested array items.
        if (($cleanedProperty->items ?? null) !== null) {
            $cleanedProperty->items = $this->cleanPropertyForValidation(
                propertySchema: $cleanedProperty->items,
                isArrayItems: true
            );
        }

        return $cleanedProperty;
    }//end cleanPropertyForValidation()

    /**
     * Transforms custom OpenRegister types to valid JSON Schema types.
     *
     * JSON Schema only allows: string, number, integer, boolean, array, object, null.
     * OpenRegister uses custom types like "file" which need to be converted.
     *
     * @param object $propertySchema The property schema to transform
     *
     * @return object The transformed property schema with valid JSON Schema types
     */
    private function transformCustomTypeToJsonSchemaType(object $propertySchema): object
    {
        // Map of custom OpenRegister types to their JSON Schema equivalents.
        $customTypeMap = [
            'file'     => 'string',  // File references are stored as strings (paths, UUIDs, etc.)
            'datetime' => 'string',  // Datetime values are stored as ISO 8601 strings
            'date'     => 'string',  // Date values are stored as strings
            'time'     => 'string',  // Time values are stored as strings
            'uuid'     => 'string',  // UUIDs are strings
            'url'      => 'string',  // URLs are strings
            'email'    => 'string',  // Emails are strings
            'phone'    => 'string',  // Phone numbers are strings
        ];

        // Check if type is set and needs transformation.
        if (isset($propertySchema->type) === false) {
            return $propertySchema;
        }

        $type = $propertySchema->type;

        // Handle single type as string.
        if (is_string($type) === true && isset($customTypeMap[$type]) === true) {
            $propertySchema->type = $customTypeMap[$type];
        }

        // Handle type as array (e.g., ["file", "null"]).
        if (is_array($type) === true) {
            $propertySchema->type = array_map(
                function ($t) use ($customTypeMap) {
                    return $customTypeMap[$t] ?? $t;
                },
                $type
            );
        }

        return $propertySchema;
    }//end transformCustomTypeToJsonSchemaType()

    /**
     * Transforms array items for validation by converting object items to appropriate types.
     *
     * @param object $itemsSchema The array items schema to transform
     *
     * @return object The transformed items schema
     */
    private function transformArrayItemsForValidation(object $itemsSchema): object
    {

        // If items don't have a type or aren't objects, return as-is.
        if (isset($itemsSchema->type) === false || $itemsSchema->type !== 'object') {
            return $itemsSchema;
        }

        // Check if this has objectConfiguration to determine handling.
        // Handle both array and object formats for objectConfiguration
        $config = $itemsSchema->objectConfiguration ?? null;
        $handling = null;
        if (is_array($config) && isset($config['handling'])) {
            $handling = $config['handling'];
        } elseif (is_object($config) && isset($config->handling)) {
            $handling = $config->handling;
        }

        if ($config !== null && $handling !== null) {
            switch ($handling) {
                case 'related-object':
                    // For related objects, convert to UUID strings.
                    return $this->transformItemsToUuidStrings($itemsSchema);

                case 'nested-object':
                    // For nested objects, create a simple object structure.
                    return $this->transformItemsToSimpleObject($itemsSchema);

                default:
                    // For other handling types, convert to UUID strings as default.
                    return $this->transformItemsToUuidStrings($itemsSchema);
            }
        }

        // If no objectConfiguration, check if there's a $ref.
        if (($itemsSchema->{'$ref'} ?? null) !== null) {
            // Convert to UUID strings for any referenced objects.
            return $this->transformItemsToUuidStrings($itemsSchema);
        }

        // Default: convert to simple object structure.
        return $this->transformItemsToSimpleObject($itemsSchema);
    }//end transformArrayItemsForValidation()

    /**
     * Transforms array items to expect UUID strings.
     *
     * @param object $itemsSchema The array items schema to transform
     *
     * @return object The transformed schema expecting UUID strings
     */
    private function transformItemsToUuidStrings(object $itemsSchema): object
    {

        // Remove all object-specific properties.
        unset($itemsSchema->properties, $itemsSchema->required, $itemsSchema->{'$ref'});

        // Set to string type with UUID pattern.
        $itemsSchema->type        = 'string';
        $itemsSchema->pattern     = '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$';
        $itemsSchema->description = 'UUID reference to a related object';

        return $itemsSchema;
    }//end transformItemsToUuidStrings()

    /**
     * Transforms array items to a simple object structure.
     *
     * @param object $itemsSchema The array items schema to transform
     *
     * @return object The transformed schema with simple object structure
     */
    private function transformItemsToSimpleObject(object $itemsSchema): object
    {

        // Remove $ref to prevent circular references.
        unset($itemsSchema->{'$ref'});

        // Create a simple object structure.
        $itemsSchema->type        = 'object';
        $itemsSchema->description = 'Nested object';

        // Add basic properties that most objects should have.
        $itemsSchema->properties = (object) [
            'id' => (object) [
                'type'        => 'string',
                'description' => 'Object identifier',
            ],
        ];

        return $itemsSchema;
    }//end transformItemsToSimpleObject()

    /**
     * Checks if a property schema is a self-reference to the given schema slug.
     *
     * @param object $propertySchema The property schema to check
     * @param string $schemaSlug     The schema slug to check against
     *
     * @return bool True if this is a self-reference
     */
    private function isSelfReference(object $propertySchema, string $schemaSlug): bool
    {
        // Check for $ref in the property.
        if (($propertySchema->{'$ref'} ?? null) !== null) {
            $ref = $propertySchema->{'$ref'};

            // Handle both string and object formats for $ref.
            $refId = $ref;
            if (is_object($ref) === true && (($ref->id ?? null) !== null)) {
                $refId = $ref->id;
            } else if (is_array($ref) === true && (($ref['id'] ?? null) !== null)) {
                $refId = $ref['id'];
            }

            // Extract schema slug from reference path.
            if (is_string($refId) === true && str_contains($refId, '#/components/schemas/') === true) {
                // Remove query parameters if present.
                $cleanRefId     = $this->removeQueryParameters($refId);
                $referencedSlug = substr($cleanRefId, strrpos($cleanRefId, '/') + 1);
                return $referencedSlug === $schemaSlug;
            }
        }

        return false;
    }//end isSelfReference()

    /**
     * Finds a schema by slug (case-insensitive).
     *
     * @param string $slug The schema slug to find
     *
     * @return Schema|null The found schema or null if not found
     */
    private function findSchemaBySlug(string $slug): ?Schema
    {
        try {
            // Try direct slug match first using the find method which supports slug lookups.
            $schema = $this->schemaMapper->find($slug);
            if ($schema !== null) {
                return $schema;
            }
        } catch (Exception $e) {
            // Continue with case-insensitive search.
        }

        // Try case-insensitive search.
        try {
            $schemas = $this->schemaMapper->findAll();
            foreach ($schemas as $schema) {
                if (strcasecmp($schema->getSlug(), $slug) === 0) {
                    return $schema;
                }
            }
        } catch (Exception $e) {
            // Failed to fetch schemas, returning null.
            $this->logger->debug('Failed to find schema by slug', ['slug' => $slug, 'exception' => $e->getMessage()]);
        }

        return null;
    }//end findSchemaBySlug()

    /**
     * Validates an object against a schema.
     *
     * @param array           $object       The object to validate.
     * @param Schema|int|null $schema       The schema or schema ID to validate against.
     * @param object          $schemaObject A custom schema object for validation.
     * @param int             $_depth       The depth level for validation (unused).
     *
     * @return ValidationResult The result of the validation.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Comprehensive validation with many edge case handlers
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple validation scenarios and schema types
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complete validation logic requires extensive handling
     */
    public function validateObject(
        array $object,
        Schema | int | string | null $schema=null,
        object $schemaObject=new stdClass(),
        int $_depth=0
    ): ValidationResult {

        // Use == because === will never be true when comparing stdClass-instances.
        // Phpcs:ignore Squiz.Operators.ComparisonOperatorUsage.NotAllowed
        if ($schemaObject == new stdClass()) {
            if ($schema instanceof Schema) {
                $schemaObject = $schema->getSchemaObject($this->urlGenerator);
            } else if ($schema !== null) {
                // Handle int or string schema ID.
                $schemaObject = $this->schemaMapper->find($schema)->getSchemaObject($this->urlGenerator);
            }
        }//end if

        $this->validateUniqueFields(object: $object, schema: $schema);

        // Get the current schema slug for circular reference detection.
        $currentSchemaSlug = '';
        if ($schema instanceof Schema) {
            $currentSchemaSlug = $schema->getSlug();
        }

        // Transform schema for validation (handles circular references, OpenRegister configs, and schema resolution).
        [$schemaObject, $object] = $this->transformSchemaForValidation(
            schemaObject: $schemaObject,
            object: $object,
            currentSchemaSlug: $currentSchemaSlug
        );

        // Clean the schema by removing all Nextcloud-specific metadata properties.
        $schemaObject = $this->cleanSchemaForValidation($schemaObject);

        // Log the final schema object before validation.
        // If schemaObject reuired is empty unset it.
        if (($schemaObject->required ?? null) !== null && empty($schemaObject->required) === true) {
            unset($schemaObject->required);
        }

        // If there are no properties, we don't need to validate.
        // Skip validation ONLY if properties are NOT set OR if properties are empty.
        if (isset($schemaObject->properties) === false || empty($schemaObject->properties) === true) {
            // Validate against an empty schema object to get a valid ValidationResult.
            $validator = new Validator();
            return $validator->validate(json_decode(json_encode($object)), new stdClass());
        }

        // @todo This should be done earlier.
        unset($object['extend'], $object['filters']);

        // Remove only truly empty values that have no validation significance.
        // Keep empty strings for required fields so they can fail validation with proper error messages.
        $requiredFields = $schemaObject->required ?? [];
        $object         = array_filter(
            $object,
            function ($value, $key) use ($requiredFields, $schemaObject) {
                // Always keep required fields, even if they're empty strings (they should fail validation).
                if (in_array($key, $requiredFields) === true) {
                    return true;
                }

                // Check if this is an enum field.
                $propertySchema = $schemaObject->properties->$key ?? null;
                if (($propertySchema !== null) === true
                    && (($propertySchema->enum ?? null) !== null) === true
                    && is_array($propertySchema->enum) === true
                ) {
                    // For enum fields, only keep null if it's explicitly allowed in the enum.
                    if ($value === null && in_array(null, $propertySchema->enum) === false) {
                        return false;
                        // Remove null values for enum fields that don't allow null.
                    }
                }

                // For non-required fields, filter out empty arrays ONLY if they have no validation constraints.
                // Keep empty arrays if they have minItems, maxItems, or other array validation rules.
                if (is_array($value) === true && empty($value) === true) {
                    // Check if this field has array validation constraints.
                    if (($propertySchema !== null) === true) {
                        $hasMinItems    = isset($propertySchema->minItems) && $propertySchema->minItems > 0;
                        $hasMaxItems    = isset($propertySchema->maxItems);
                        $hasUniqueItems = isset($propertySchema->uniqueItems) && $propertySchema->uniqueItems === true;

                        // Keep empty arrays if they have validation constraints (should fail validation).
                        if ($hasMinItems === true || $hasMaxItems === true || $hasUniqueItems === true) {
                            return true;
                        }
                    }

                    return false;
                    // Remove empty arrays for non-required fields without validation constraints.
                }

                if ($value === '') {
                    return false;
                    // Remove empty strings for non-required fields.
                }

                // Keep everything else (including null, 0, false, etc.).
                return true;
            },
            ARRAY_FILTER_USE_BOTH
        );

        /*
         * Modify schema to allow null values for non-required fields.
         * This ensures that null values are valid for optional fields.
         * @psalm-suppress NoValue
         */

        if (property_exists($schemaObject, 'properties') === true) {
            $properties = $schemaObject->properties;

            /*
             * @psalm-suppress TypeDoesNotContainType
             */

            // Handle both array and object (stdClass) types for properties
            if (isset($properties) === true && (is_array($properties) === true || is_object($properties) === true)) {
                foreach ($properties as $propertyName => $propertySchema) {
                    // Skip required fields - they should not allow null unless explicitly defined.
                    if (in_array($propertyName, $requiredFields) === true) {
                        continue;
                    }

                    // Special handling for enum fields - only allow null if not explicitly defined in enum.
                    if (($propertySchema->enum ?? null) !== null && is_array($propertySchema->enum) === true) {
                        // If enum doesn't include null, don't add it automatically.
                        // Enum fields should be either set to a valid enum value or omitted entirely.
                        if (in_array(null, $propertySchema->enum, true) === false) {
                            continue;
                        }
                    }

                    // For non-required fields, allow null values by modifying the type.
                    if (($propertySchema->type ?? null) !== null && is_string($propertySchema->type) === true) {
                        // Convert single type to array with null support.
                        $propertySchema->type = [$propertySchema->type, 'null'];
                    } else if (($propertySchema->type ?? null) !== null && is_array($propertySchema->type) === true) {
                        // Add null to existing type array if not already present.
                        if (in_array('null', $propertySchema->type, true) === false) {
                            $propertySchema->type[] = 'null';
                        }
                    }
                }//end foreach
            }//end if
        }//end if

        $validator = new Validator();
        $validator->setMaxErrors(100);

        // Register custom format validators using our helper method that supports named parameters.
        $this->registerCustomFormat(validator: $validator, type: 'string', format: 'bsn', resolver: new BsnFormat());
        $this->registerCustomFormat(validator: $validator, type: 'string', format: 'semver', resolver: new SemVerFormat());

        $validator->loader()->resolver()->registerProtocol('http', [$this, 'resolveSchema']);

        return $validator->validate(json_decode(json_encode($object)), $schemaObject);
    }//end validateObject()

    /**
     * Register a custom format validator with named parameters support
     *
     * This helper method wraps the Opis\JsonSchema FormatResolver::register() method
     * to support named parameters, maintaining consistency with our codebase style.
     *
     * @param Validator $validator The validator instance
     * @param string    $type      The data type (e.g., 'string', 'number')
     * @param string    $format    The format name (e.g., 'bsn', 'semver')
     * @param object    $resolver  The format resolver instance
     *
     * @return void
     */
    private function registerCustomFormat(Validator $validator, string $type, string $format, object $resolver): void
    {
        // The underlying library doesn't support named parameters, so we convert them here.
        $validator->parser()->getFormatResolver()->register($type, $format, $resolver);
    }//end registerCustomFormat()

    /**
     * Resolves a schema from a given URI.
     *
     * @param Uri $uri The URI pointing to the schema.
     *
     * @return string The schema content in JSON format.
     *
     * @throws GuzzleException If there is an error during schema fetching.
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     *
     * @SuppressWarnings(PHPMD.StaticAccess) Uri::fromParts is standard GuzzleHttp\Psr7 pattern
     */
    public function resolveSchema(Uri $uri): string
    {
        // Local schema resolution.
        if ($this->urlGenerator->getBaseUrl() === $uri->scheme().'://'.$uri->host()
            && str_contains($uri->path() ?? '', '/api/schemas') === true
        ) {
            $exploded = explode('/', $uri->path() ?? '');
            $schema   = $this->schemaMapper->find(end($exploded));

            return json_encode($schema->getSchemaObject($this->urlGenerator));
        }

        // File schema resolution.
        if ($this->urlGenerator->getBaseUrl() === $uri->scheme().'://'.$uri->host()
            && str_contains($uri->path(), '/api/files/schema') === true
        ) {
            // Return a basic file schema object.
            // TODO: Implement proper file schema resolution.
            $fileSchema = (object) [
                'type'       => 'object',
                'properties' => (object) [
                    'id'       => (object) ['type' => 'integer'],
                    'name'     => (object) ['type' => 'string'],
                    'path'     => (object) ['type' => 'string'],
                    'mimetype' => (object) ['type' => 'string'],
                    'size'     => (object) ['type' => 'integer'],
                ],
            ];
            return json_encode($fileSchema);
        }

        // External schema resolution.
        if ($this->config->getValueBool('openregister', 'allowExternalSchemas') === true) {
            $client = new Client();
            $result = $client->get(\GuzzleHttp\Psr7\Uri::fromParts($uri->components()));

            return $result->getBody()->getContents();
        }

        return '';
    }//end resolveSchema()

    /**
     * Removes query parameters from a reference string.
     *
     * @param string $reference The reference string that may contain query parameters
     *
     * @return string The reference string without query parameters
     */
    private function removeQueryParameters(string $reference): string
    {
        // Remove query parameters if present (e.g., "schema?key=value" -> "schema").
        if (str_contains($reference, '?') === true) {
            return substr($reference, 0, strpos($reference, '?'));
        }

        return $reference;
    }//end removeQueryParameters()

    /**
     * Generates a meaningful error message from a validation result.
     *
     * This method creates clear, user-friendly error messages instead of using
     * the generic Opis error message like "The required properties ({missing}) are missing".
     *
     * @param ValidationResult $result The validation result from Opis JsonSchema.
     *
     * @return string A meaningful error message.
     */
    public function generateErrorMessage(ValidationResult $result): string
    {
        if ($result->isValid() === true) {
            return 'Validation passed';
        }

        // Get the primary validation error.
        $error = $result->error();

        return $this->formatValidationError($error);
    }//end generateErrorMessage()

    /**
     * Formats a validation error into a user-friendly message.
     *
     * @param \Opis\JsonSchema\Errors\ValidationError $error The validation error.
     *
     * @return string A formatted error message.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Many validation error types require specific formatting
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive error formatting for all validation types
     */
    private function formatValidationError(\Opis\JsonSchema\Errors\ValidationError $error): string
    {
        $keyword  = $error->keyword();
        $dataPath = $error->data()->fullPath();
        $value    = $error->data()->value();
        $args     = $error->args();

        // Build property path for better identification.
        $propertyPath = implode('.', $dataPath);
        if (empty($dataPath) === true) {
            $propertyPath = 'root';
        }

        switch ($keyword) {
            case 'required':
                $missing = $args['missing'] ?? [];
                if (is_array($missing) === true && count($missing) > 0) {
                    if (count($missing) === 1) {
                        $property = $missing[0];
                        $hint     = 'Please provide a value for this property or set it to null if allowed.';
                        return "The required property ({$property}) is missing. {$hint}";
                    }

                    $missingList = implode(', ', $missing);
                    $msg         = "The required properties ({$missingList}) are missing. ";
                    return $msg.'Please provide values for these properties.';
                }
                return 'Required property is missing';

            case 'type':
                $expectedType = $args['expected'] ?? 'unknown';
                $actualType   = $this->getValueType($value);

                // Handle array type definitions (e.g., ["array"] or ["string", "null"])
                if (is_array($expectedType)) {
                    $expectedType = implode(' or ', $expectedType);
                }

                // Provide specific guidance for empty values.
                if ($expectedType === 'object' && (is_array($value) === true && empty($value) === true)) {
                    $hint1 = 'For non-required object properties, set this to null to clear the field.';
                    $hint2 = 'For required object properties, provide a valid object with the necessary properties.';
                    return "Property '{$propertyPath}' expects object but got empty ({}). {$hint1} {$hint2}";
                }

                if ($expectedType === 'array' && (is_array($value) === true && empty($value) === true)) {
                    $hint = 'This likely has a minItems constraint. Please provide at least one item.';
                    return "Property '{$propertyPath}' expects non-empty array but got empty array ([]). {$hint}";
                }

                if ($expectedType === 'string' && $value === '') {
                    $hint1 = 'For non-required string properties, set this to null to clear the field.';
                    $hint2 = 'For required string properties, provide a valid string value.';
                    return "Property '{$propertyPath}' expects non-empty string but got empty string. {$hint1} {$hint2}";
                }

                $hint = 'Please provide a value of the correct type.';
                return "Property '{$propertyPath}' should be type '{$expectedType}' but is '{$actualType}'. {$hint}";

            case 'minItems':
                $minItems    = $args['min'] ?? 0;
                $actualItems = 0;
                if (is_array($value) === true) {
                    $actualItems = count($value);
                }

                $hint = 'Please add more items to the array or set to null if the property is not required.';
                return "Property '{$propertyPath}' requires at least {$minItems} items, has {$actualItems}. {$hint}";

            case 'maxItems':
                $maxItems    = $args['max'] ?? 0;
                $actualItems = 0;
                if (is_array($value) === true) {
                    $actualItems = count($value);
                }

                $hint = 'Please remove some items from the array.';
                return "Property '{$propertyPath}' allows at most {$maxItems} items, has {$actualItems}. {$hint}";

            case 'format':
                $format = $args['format'] ?? 'unknown';
                $hint   = 'Please provide a value in the correct format.';
                return "Property '{$propertyPath}' should match format '{$format}' but '{$value}' does not. {$hint}";

            case 'minLength':
                $minLength    = $args['min'] ?? 0;
                $actualLength = 0;
                if (is_string($value) === true) {
                    $actualLength = strlen($value);
                }

                if ($actualLength === 0) {
                    $hint = 'Please provide a non-empty string value.';
                    return "Property '{$propertyPath}' requires at least {$minLength} characters, but is empty. {$hint}";
                }

                $hint = 'Please provide a longer string value.';
                return "Property '{$propertyPath}' requires at least {$minLength} chars, has {$actualLength}. {$hint}";

            case 'maxLength':
                $maxLength    = $args['max'] ?? 0;
                $actualLength = 0;
                if (is_string($value) === true) {
                    $actualLength = strlen($value);
                }

                $hint = 'Please provide a shorter string value.';
                return "Property '{$propertyPath}' allows at most {$maxLength} chars, has {$actualLength}. {$hint}";

            case 'minimum':
                $minimum = $args['min'] ?? 0;
                $msg     = "Property '{$propertyPath}' should be at least {$minimum}, ";
                return $msg."but is {$value}. Please provide a larger number.";

            case 'maximum':
                $maximum = $args['max'] ?? 0;
                $msg     = "Property '{$propertyPath}' should be at most {$maximum}, ";
                return $msg."but is {$value}. Please provide a smaller number.";

            case 'enum':
                $allowedValues = $args['values'] ?? [];
                if (is_array($allowedValues) === true) {
                    $valuesList = implode(
                        ', ',
                        array_map(
                            function ($v) {
                                return "'{$v}'";
                            },
                            $allowedValues
                        )
                    );
                    $msg        = "Property '{$propertyPath}' should be one of: {$valuesList}, ";
                    return $msg."but is '{$value}'. Please choose one of the allowed values.";
                }

                $msg = "Property '{$propertyPath}' has an invalid value '{$value}'. ";
                return $msg.'Please provide one of the allowed values.';

            case 'pattern':
                $pattern = $args['pattern'] ?? 'unknown';
                $hint    = 'Please provide a value that matches the required pattern.';
                return "Property '{$propertyPath}' should match pattern '{$pattern}' but '{$value}' does not. {$hint}";

            default:
                // Check for sub-errors to provide more specific messages.
                $subErrors = $error->subErrors();
                if (empty($subErrors) === false) {
                    return $this->formatValidationError($subErrors[0]);
                }

                $msg = "Property '{$propertyPath}' failed validation for rule '{$keyword}'. ";
                return $msg.'Please check the property value and schema requirements.';
        }//end switch
    }//end formatValidationError()

    /**
     * Gets a human-readable type name for a value.
     *
     * @param mixed $value The value to get the type for.
     *
     * @return string The type name.
     */
    private function getValueType($value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value) === true) {
            return 'boolean';
        }

        if (is_int($value) === true) {
            return 'integer';
        }

        if (is_float($value) === true) {
            return 'number';
        }

        if (is_string($value) === true) {
            return 'string';
        }

        if (is_array($value) === true) {
            return 'array';
        }

        if (is_object($value) === true) {
            return 'object';
        }

        return 'unknown';
    }//end getValueType()

    /**
     * Handles validation exceptions by formatting them into a JSON response.
     *
     * @param ValidationException|CustomValidationException $exception The validation exception.
     *
     * @return JSONResponse JSON error response with validation errors and 400 status code.
     */
    public function handleValidationException(ValidationException | CustomValidationException $exception): JSONResponse
    {
        $errors = [];
        if ($exception instanceof ValidationException === false) {
            foreach ($exception->getErrors() as $error) {
                $errors[] = $error;
            }

            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'message' => 'Validation failed',
                    'errors'  => $errors,
                ],
                statusCode: 400
            );
        }

        // The exception message should already be meaningful thanks to generateErrorMessage().
        $property = null;
        if (method_exists($exception, 'getProperty') === true) {
            $property = $exception->getProperty();
        }

        $errors[] = [
            'property' => $property,
            'message'  => $exception->getMessage(),
            'errors'   => (new ErrorFormatter())->format($exception->getErrors()),
        ];

        return new JSONResponse(
            data: [
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $errors,
            ],
            statusCode: 400
        );
    }//end handleValidationException()

    /**
     * Check of the value of a parameter, or a combination of parameters, is unique
     *
     * @param array  $object The object to check
     * @param Schema $schema The schema of the object
     *
     * @return void
     * @throws CustomValidationException
     */
    private function validateUniqueFields(array $object, Schema $schema): void
    {
        $config       = $schema->getConfiguration();
        $uniqueFields = $config['unique'] ?? null;

        // BUGFIX: Early return if no unique fields are configured.
        if (empty($uniqueFields) === true) {
            return;
        }

        $filters = [];
        if (is_array($uniqueFields) === true) {
            foreach ($uniqueFields as $field) {
                $filters[$field] = $object[$field];
            }
        } else if (is_string($uniqueFields) === true) {
            $filters[$uniqueFields] = $object[$uniqueFields];
        }

        $count = $this->objectMapper->countAll(_filters: $filters, schema: $schema);

        if ($count !== 0) {
            // IMPROVED ERROR MESSAGE: Show which field(s) caused the uniqueness violation.
            $fieldNames = $uniqueFields;
            if (is_array($uniqueFields) === true) {
                $fieldNames = implode(', ', $uniqueFields);
            }

            $fieldValues = $uniqueFields.'='.($object[$uniqueFields] ?? 'null');
            if (is_array($uniqueFields) === true) {
                $fieldValues = implode(
                    ', ',
                    array_map(
                        function ($field) use ($object) {
                            return $field.'='.($object[$field] ?? 'null');
                        },
                        $uniqueFields
                    )
                );
            }

            $errorName = (string) $uniqueFields;
            if (is_array($uniqueFields) === true) {
                $errorName = (string) (array_shift($uniqueFields) ?? 'uniqueField');
            }

            $errMsg  = "The identifying fields ({$fieldNames}) are not unique. ";
            $errMsg .= "Found duplicate values: {$fieldValues}";
            throw new CustomValidationException(
                message: "Fields are not unique: {$fieldNames} (values: {$fieldValues})",
                errors: [
                    $errorName => $errMsg,
                ]
            );
        }//end if
    }//end validateUniqueFields()
}//end class
