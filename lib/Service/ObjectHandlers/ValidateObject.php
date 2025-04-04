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
 * @package   OCA\OpenRegister\Service\ObjectHandlers
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 */

namespace OCA\OpenRegister\Service\ObjectHandlers;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\File;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Formats\BsnFormat;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
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
     */
    public function __construct(
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $config
    ) {

    }//end __construct()


    /**
     * Validates an object against a schema.
     *
     * @param array    $object       The object to validate.
     * @param int|null $schemaId     The schema ID to validate against.
     * @param object   $schemaObject A custom schema object for validation.
     * @param int      $depth        The depth level for validation.
     *
     * @return ValidationResult The result of the validation.
     */
    public function validateObject(
        array $object,
        ?int $schemaId=null,
        object $schemaObject=new stdClass(),
        int $depth=0
    ): ValidationResult {
        if ($schemaObject === new stdClass() || $schemaId !== null) {
            $schemaObject = $this->schemaMapper->find($schemaId)->getSchemaObject($this->urlGenerator);
        }

        // If there are no properties, we don't need to validate.
        if (isset($schemaObject->properties) === false || empty($schemaObject->properties) === true) {
            // Return a default ValidationResult indicating success.
            return new ValidationResult(null);
        }

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
            $errors[] = [
                'property' => $exception->getProperty(),
                'message'  => $exception->getMessage(),
            ];
        } else {
            foreach ($exception->getErrors() as $error) {
                $errors[] = [
                    'property' => $error['property'],
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
