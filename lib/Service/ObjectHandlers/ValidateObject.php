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

        // Remove empty properties and empty arrays from the object.
        $object = array_filter($object, function ($value) {
            // Check if the value is not an empty array or an empty property.
            // @todo we are filtering out arrays here, but we should not. This should be fixed in the validator.
            return !(is_array($value) && empty($value)) && $value !== null && $value !== '' && is_array($value) === false;
        });

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
        $keyword = $error->keyword();
        $dataPath = $error->data()->fullPath();
        $value = $error->data()->value();
        $args = $error->args();

        // Build property path for better identification
        $propertyPath = empty($dataPath) ? 'root' : implode('.', $dataPath);

        switch ($keyword) {
            case 'required':
                $missing = $args['missing'] ?? [];
                if (is_array($missing) && count($missing) > 0) {
                    if (count($missing) === 1) {
                        return "The required property '{$missing[0]}' is missing";
                    }
                    $missingList = implode(', ', $missing);
                    return "The required properties ({$missingList}) are missing";
                }
                return 'Required property is missing';

            case 'type':
                $expectedType = $args['expected'] ?? 'unknown';
                $actualType = $this->getValueType($value);
                return "Property '{$propertyPath}' should be of type '{$expectedType}' but is '{$actualType}'";

            case 'format':
                $format = $args['format'] ?? 'unknown';
                return "Property '{$propertyPath}' should match the format '{$format}' but the value '{$value}' does not";

            case 'minLength':
                $minLength = $args['min'] ?? 0;
                $actualLength = is_string($value) ? strlen($value) : 0;
                return "Property '{$propertyPath}' should have at least {$minLength} characters, but has {$actualLength}";

            case 'maxLength':
                $maxLength = $args['max'] ?? 0;
                $actualLength = is_string($value) ? strlen($value) : 0;
                return "Property '{$propertyPath}' should have at most {$maxLength} characters, but has {$actualLength}";

            case 'minimum':
                $minimum = $args['min'] ?? 0;
                return "Property '{$propertyPath}' should be at least {$minimum}, but is {$value}";

            case 'maximum':
                $maximum = $args['max'] ?? 0;
                return "Property '{$propertyPath}' should be at most {$maximum}, but is {$value}";

            case 'enum':
                $allowedValues = $args['values'] ?? [];
                if (is_array($allowedValues)) {
                    $valuesList = implode(', ', array_map(function($v) { return "'{$v}'"; }, $allowedValues));
                    return "Property '{$propertyPath}' should be one of: {$valuesList}, but is '{$value}'";
                }
                return "Property '{$propertyPath}' has an invalid value '{$value}'";

            case 'pattern':
                $pattern = $args['pattern'] ?? 'unknown';
                return "Property '{$propertyPath}' should match the pattern '{$pattern}' but the value '{$value}' does not";

            default:
                // Check for sub-errors to provide more specific messages
                $subErrors = $error->subErrors();
                if (!empty($subErrors)) {
                    return $this->formatValidationError($subErrors[0]);
                }
                
                return "Property '{$propertyPath}' failed validation for rule '{$keyword}'";
        }

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
                'errors' => (new ErrorFormatter())->format($exception->getErrors()),
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
