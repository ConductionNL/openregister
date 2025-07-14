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

namespace OCA\OpenRegister\Service\ObjectHandlers;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\File;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Formats\BsnFormat;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use Opis\Uri\Uri;
use stdClass;

/**
 * Handler class for validating objects in the OpenRegister application.
 *
 * This handler is responsible for validating objects against their schemas,
 * including custom validation rules and error handling.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\ObjectHandlers
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   1.0.0
 * @copyright 2024 Conduction b.v.
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
     * Constructor for ValidateObject handler.
     *
     * @param IURLGenerator $urlGenerator URL generator service.
     * @param IAppConfig    $config       Application configuration service.
     * @param SchemaMapper  $schemaMapper Schema mapper service.
     */
    public function __construct(
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $config,
        private readonly SchemaMapper $schemaMapper,
    ) {

    }//end __construct()


    /**
     * Pre-processes a schema object to resolve all schema references.
     *
     * This method recursively walks through the schema object and replaces
     * any "#/components/schemas/[slug]" references with the actual schema definitions.
     * This ensures the validation library can work with fully resolved schemas.
     *
     * @param object $schemaObject The schema object to process
     * @param array  $visited      Array to track visited schemas to prevent infinite loops
     *
     * @return object The processed schema object with resolved references
     */
    private function preprocessSchemaReferences(object $schemaObject, array $visited=[]): object
    {
        // Clone the schema object to avoid modifying the original
        $processedSchema = json_decode(json_encode($schemaObject));

        // Recursively process all properties
        if (isset($processedSchema->properties)) {
            foreach ($processedSchema->properties as $propertyName => $propertySchema) {
                $processedSchema->properties->$propertyName = $this->resolveSchemaProperty($propertySchema, $visited);
            }
        }

        // Process array items if present
        if (isset($processedSchema->items)) {
            $processedSchema->items = $this->resolveSchemaProperty($processedSchema->items, $visited);
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
     */
    private function resolveSchemaProperty(object $propertySchema, array $visited=[]): object
    {
        // Handle $ref references
        if (isset($propertySchema->{'$ref'})) {
            $reference = $propertySchema->{'$ref'};

            // Check if this is a schema reference we should resolve
            if (str_contains($reference, '/components/schemas/')) {
                $schemaSlug = substr($reference, strrpos($reference, '/') + 1);

                // Prevent infinite loops
                if (in_array($schemaSlug, $visited)) {
                    error_log("[ValidateObject] Circular reference detected for schema: $schemaSlug");
                    return $propertySchema;
                }

                // Try to resolve the schema
                $referencedSchema = $this->findSchemaBySlug($schemaSlug);
                if ($referencedSchema) {
                    error_log("[ValidateObject] Resolving schema reference '$reference' to schema: {$referencedSchema->getSlug()}");

                    // Get the referenced schema object and recursively process it
                    $referencedSchemaObject = $referencedSchema->getSchemaObject($this->urlGenerator);
                    $newVisited     = array_merge($visited, [$schemaSlug]);
                    $resolvedSchema = $this->preprocessSchemaReferences($referencedSchemaObject, $newVisited);

                    // For object properties, we need to handle both nested objects and UUID references
                    if (isset($propertySchema->type) && $propertySchema->type === 'object') {
                        // Create a union type that allows both the full object and a UUID string
                        $unionSchema        = new \stdClass();
                        $unionSchema->oneOf = [
                            $resolvedSchema,
                        // Full object
                            (object) [
                        // UUID string
                                'type'    => 'string',
                                'pattern' => '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
                            ],
                        ];

                        // Copy any other properties from the original schema
                        foreach ($propertySchema as $key => $value) {
                            if ($key !== '$ref' && $key !== 'type') {
                                $unionSchema->$key = $value;
                            }
                        }

                        return $unionSchema;
                    } else {
                        // For non-object properties, just return the resolved schema
                        // but preserve any additional properties from the original
                        foreach ($propertySchema as $key => $value) {
                            if ($key !== '$ref') {
                                $resolvedSchema->$key = $value;
                            }
                        }

                        return $resolvedSchema;
                    }//end if
                } else {
                    error_log("[ValidateObject] Could not resolve schema reference: $reference");
                }//end if
            }//end if
        }//end if

        // Handle array items with $ref
        if (isset($propertySchema->items) && isset($propertySchema->items->{'$ref'})) {
            $propertySchema->items = $this->resolveSchemaProperty($propertySchema->items, $visited);
        }

        // Recursively process nested properties
        if (isset($propertySchema->properties)) {
            foreach ($propertySchema->properties as $nestedPropertyName => $nestedPropertySchema) {
                $propertySchema->properties->$nestedPropertyName = $this->resolveSchemaProperty($nestedPropertySchema, $visited);
            }
        }

        return $propertySchema;

    }//end resolveSchemaProperty()


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
            // Try direct slug match first using the find method which supports slug lookups
            $schema = $this->schemaMapper->find($slug);
            if ($schema) {
                return $schema;
            }
        } catch (Exception $e) {
            // Continue with case-insensitive search
        }

        // Try case-insensitive search
        try {
            $schemas = $this->schemaMapper->findAll();
            foreach ($schemas as $schema) {
                if (strcasecmp($schema->getSlug(), $slug) === 0) {
                    return $schema;
                }
            }
        } catch (Exception $e) {
            error_log("[ValidateObject] Error searching schemas by slug: ".$e->getMessage());
        }

        return null;

    }//end findSchemaBySlug()


    /**
     * Validates an object against a schema.
     *
     * @param array           $object       The object to validate.
     * @param Schema|int|null $schema       The schema or schema ID to validate against.
     * @param object          $schemaObject A custom schema object for validation.
     * @param int             $depth        The depth level for validation.
     *
     * @return ValidationResult The result of the validation.
     */
    public function validateObject(
        array $object,
        Schema | int | string | null $schema=null,
        object $schemaObject=new stdClass(),
        int $depth=0
    ): ValidationResult {

        // Use == because === will never be true when comparing stdClass-instances
        if ($schemaObject == new stdClass()) {
            if ($schema instanceof Schema) {
                $schemaObject = $schema->getSchemaObject($this->urlGenerator);
            } else if (is_int($schema) === true || is_string($schema) === true) {
                $schemaObject = $this->schemaMapper->find($schema)->getSchemaObject($this->urlGenerator);
            }
        }

        // Pre-process the schema to resolve all schema references
        $schemaObject = $this->preprocessSchemaReferences($schemaObject);

        // If schemaObject reuired is empty unset it.
        if (isset($schemaObject->required) === true && empty($schemaObject->required) === true) {
            unset($schemaObject->required);
        }

        // If there are no properties, we don't need to validate.
        if (isset($schemaObject->properties) === false || empty($schemaObject->properties) === true) {
            // Return a ValidationResult with null data indicating success.
            return new ValidationResult(null, null);
        }

        // @todo This should be done earlier
        unset($object['extend'], $object['filters']);

        // Remove only truly empty values that have no validation significance
        // Keep empty strings for required fields so they can fail validation with proper error messages
        $requiredFields = $schemaObject->required ?? [];
        $object         = array_filter(
                $object,
                function ($value, $key) use ($requiredFields, $schemaObject) {
                    // Always keep required fields, even if they're empty strings (they should fail validation)
                    if (in_array($key, $requiredFields)) {
                        return true;
                    }

                    // Check if this is an enum field
                    $propertySchema = $schemaObject->properties->$key ?? null;
                    if ($propertySchema && isset($propertySchema->enum) && is_array($propertySchema->enum)) {
                        // For enum fields, only keep null if it's explicitly allowed in the enum
                        if ($value === null && !in_array(null, $propertySchema->enum)) {
                            return false;
                            // Remove null values for enum fields that don't allow null
                        }
                    }

                    // For non-required fields, filter out empty arrays and empty strings
                    // but keep null values (explicit clearing) and all other values
                    if (is_array($value) && empty($value)) {
                        return false;
                        // Remove empty arrays for non-required fields
                    }

                    if ($value === '') {
                        return false;
                        // Remove empty strings for non-required fields
                    }

                    // Keep everything else (including null, 0, false, etc.)
                    return true;
                },
                ARRAY_FILTER_USE_BOTH
                );

        // Modify schema to allow null values for non-required fields
        // This ensures that null values are valid for optional fields
        if (isset($schemaObject->properties)) {
            foreach ($schemaObject->properties as $propertyName => $propertySchema) {
                // Skip required fields - they should not allow null unless explicitly defined
                if (in_array($propertyName, $requiredFields)) {
                    continue;
                }

                // Special handling for enum fields - only allow null if not explicitly defined in enum
                if (isset($propertySchema->enum) && is_array($propertySchema->enum)) {
                    // If enum doesn't include null, don't add it automatically
                    // Enum fields should be either set to a valid enum value or omitted entirely
                    if (!in_array(null, $propertySchema->enum)) {
                        continue;
                    }
                }

                // For non-required fields, allow null values by modifying the type
                if (isset($propertySchema->type) && is_string($propertySchema->type)) {
                    // Convert single type to array with null support
                    $propertySchema->type = [$propertySchema->type, 'null'];
                } else if (isset($propertySchema->type) && is_array($propertySchema->type)) {
                    // Add null to existing type array if not already present
                    if (!in_array('null', $propertySchema->type)) {
                        $propertySchema->type[] = 'null';
                    }
                }
            }//end foreach
        }//end if

        $validator = new Validator();
        $validator->setMaxErrors(100);
        $validator->parser()->getFormatResolver()->register('string', 'bsn', new BsnFormat());
        $validator->loader()->resolver()->registerProtocol('http', [$this, 'resolveSchema']);

        return $validator->validate(json_decode(json_encode($object)), $schemaObject);

    }//end validateObject()


    /**
     * Resolves a schema from a given URI.
     *
     * @param Uri $uri The URI pointing to the schema.
     *
     * @return string The schema content in JSON format.
     *
     * @throws GuzzleException If there is an error during schema fetching.
     */
    public function resolveSchema(Uri $uri): string
    {
        // Local schema resolution.
        if ($this->urlGenerator->getBaseUrl() === $uri->scheme().'://'.$uri->host()
            && str_contains($uri->path(), '/api/schemas') === true
        ) {
            $exploded = explode('/', $uri->path());
            $schema   = $this->schemaMapper->find(end($exploded));

            return json_encode($schema->getSchemaObject($this->urlGenerator));
        }

        // File schema resolution.
        if ($this->urlGenerator->getBaseUrl() === $uri->scheme().'://'.$uri->host()
            && str_contains($uri->path(), '/api/files/schema') === true
        ) {
            return File::getSchema($this->urlGenerator);
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
     * Validates custom rules for an object against its schema.
     *
     * @param array  $object The object to validate.
     * @param Schema $schema The schema containing custom rules.
     *
     * @return void
     *
     * @throws ValidationException If validation fails.
     */
    private function validateCustomRules(array $object, Schema $schema): void
    {
        $customRules = $schema->getCustomRules();
        if (empty($customRules) === true) {
            return;
        }

        foreach ($customRules as $rule) {
            if (isset($rule['type']) === true && $rule['type'] === 'regex') {
                $pattern = $rule['pattern'];
                $value   = $object[$rule['property']] ?? null;

                if ($value !== null && preg_match($pattern, $value) === false) {
                    throw new ValidationException(
                        $rule['message'] ?? self::VALIDATION_ERROR_MESSAGE,
                        $rule['property']
                    );
                }
            }
        }

    }//end validateCustomRules()


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

        // Get the primary validation error
        $error = $result->error();

        return $this->formatValidationError($error);

    }//end generateErrorMessage()


    /**
     * Formats a validation error into a user-friendly message.
     *
     * @param \Opis\JsonSchema\Errors\ValidationError $error The validation error.
     *
     * @return string A formatted error message.
     */
    private function formatValidationError(\Opis\JsonSchema\Errors\ValidationError $error): string
    {
        $keyword  = $error->keyword();
        $dataPath = $error->data()->fullPath();
        $value    = $error->data()->value();
        $args     = $error->args();

        // Build property path for better identification
        $propertyPath = empty($dataPath) ? 'root' : implode('.', $dataPath);

        switch ($keyword) {
            case 'required':
                $missing = $args['missing'] ?? [];
                if (is_array($missing) && count($missing) > 0) {
                    if (count($missing) === 1) {
                        $property = $missing[0];
                        return "The required property '{$property}' is missing. Please provide a value for this property or set it to null if allowed.";
                    }

                    $missingList = implode(', ', $missing);
                    return "The required properties ({$missingList}) are missing. Please provide values for these properties.";
                }
                return 'Required property is missing';

            case 'type':
                $expectedType = $args['expected'] ?? 'unknown';
                $actualType   = $this->getValueType($value);

                // Provide specific guidance for empty values
                if ($expectedType === 'object' && (is_array($value) && empty($value))) {
                    return "Property '{$propertyPath}' should be an object but received an empty object ({}). "."For non-required object properties, you can set this to null to clear the field. "."For required object properties, provide a valid object with the necessary properties.";
                }

                if ($expectedType === 'array' && (is_array($value) && empty($value))) {
                    return "Property '{$propertyPath}' should be a non-empty array but received an empty array ([]). "."This property likely has a minItems constraint. Please provide at least one item in the array.";
                }

                if ($expectedType === 'string' && $value === '') {
                    return "Property '{$propertyPath}' should be a non-empty string but received an empty string. "."For non-required string properties, you can set this to null to clear the field. "."For required string properties, provide a valid string value.";
                }
                return "Property '{$propertyPath}' should be of type '{$expectedType}' but is '{$actualType}'. "."Please provide a value of the correct type.";

            case 'minItems':
                $minItems    = $args['min'] ?? 0;
                $actualItems = is_array($value) ? count($value) : 0;
                return "Property '{$propertyPath}' should have at least {$minItems} items, but has {$actualItems}. "."Please add more items to the array or set to null if the property is not required.";

            case 'maxItems':
                $maxItems    = $args['max'] ?? 0;
                $actualItems = is_array($value) ? count($value) : 0;
                return "Property '{$propertyPath}' should have at most {$maxItems} items, but has {$actualItems}. "."Please remove some items from the array.";

            case 'format':
                $format = $args['format'] ?? 'unknown';
                return "Property '{$propertyPath}' should match the format '{$format}' but the value '{$value}' does not. "."Please provide a value in the correct format.";

            case 'minLength':
                $minLength    = $args['min'] ?? 0;
                $actualLength = is_string($value) ? strlen($value) : 0;
                if ($actualLength === 0) {
                    return "Property '{$propertyPath}' should have at least {$minLength} characters, but is empty. "."Please provide a non-empty string value.";
                }
                return "Property '{$propertyPath}' should have at least {$minLength} characters, but has {$actualLength}. "."Please provide a longer string value.";

            case 'maxLength':
                $maxLength    = $args['max'] ?? 0;
                $actualLength = is_string($value) ? strlen($value) : 0;
                return "Property '{$propertyPath}' should have at most {$maxLength} characters, but has {$actualLength}. "."Please provide a shorter string value.";

            case 'minimum':
                $minimum = $args['min'] ?? 0;
                return "Property '{$propertyPath}' should be at least {$minimum}, but is {$value}. "."Please provide a larger number.";

            case 'maximum':
                $maximum = $args['max'] ?? 0;
                return "Property '{$propertyPath}' should be at most {$maximum}, but is {$value}. "."Please provide a smaller number.";

            case 'enum':
                $allowedValues = $args['values'] ?? [];
                if (is_array($allowedValues)) {
                    $valuesList = implode(
                            ', ',
                            array_map(
                            function ($v) {
                                return "'{$v}'";
                            },
                            $allowedValues
                            )
                            );
                    return "Property '{$propertyPath}' should be one of: {$valuesList}, but is '{$value}'. "."Please choose one of the allowed values.";
                }
                return "Property '{$propertyPath}' has an invalid value '{$value}'. "."Please provide one of the allowed values.";

            case 'pattern':
                $pattern = $args['pattern'] ?? 'unknown';
                return "Property '{$propertyPath}' should match the pattern '{$pattern}' but the value '{$value}' does not. "."Please provide a value that matches the required pattern.";

            default:
                // Check for sub-errors to provide more specific messages
                $subErrors = $error->subErrors();
                if (!empty($subErrors)) {
                    return $this->formatValidationError($subErrors[0]);
                }
                return "Property '{$propertyPath}' failed validation for rule '{$keyword}'. "."Please check the property value and schema requirements.";
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

        if (is_bool($value)) {
            return 'boolean';
        }

        if (is_int($value)) {
            return 'integer';
        }

        if (is_float($value)) {
            return 'number';
        }

        if (is_string($value)) {
            return 'string';
        }

        if (is_array($value)) {
            return 'array';
        }

        if (is_object($value)) {
            return 'object';
        }

        return 'unknown';

    }//end getValueType()


    /**
     * Handles validation exceptions by formatting them into a JSON response.
     *
     * @param ValidationException|CustomValidationException $exception The validation exception.
     *
     * @return JSONResponse The formatted error response.
     */
    public function handleValidationException(ValidationException | CustomValidationException $exception): JSONResponse
    {
        $errors = [];
        if ($exception instanceof ValidationException) {
            // The exception message should already be meaningful thanks to generateErrorMessage()
            $errors[] = [
                'property' => method_exists($exception, 'getProperty') ? $exception->getProperty() : null,
                'message'  => $exception->getMessage(),
                'errors'   => (new ErrorFormatter())->format($exception->getErrors()),
            ];
        } else {
            foreach ($exception->getErrors() as $error) {
                $errors[] = [
                    'property' => isset($error['property']) ? $error['property'] : null,
                    'message'  => $error['message'],
                ];
            }
        }

        return new JSONResponse(
            [
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $errors,
            ],
            400
        );

    }//end handleValidationException()


}//end class
