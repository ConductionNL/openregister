<?php

/**
 * ValidationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\ValidateObject;
use Psr\Log\LoggerInterface;

/**
 * Handles validation operations for ObjectService.
 *
 * This handler is responsible for:
 * - Validating objects against schemas
 * - Validating required fields
 * - Handling validation exceptions
 * - Bulk schema validation
 *
 * This replaces the standalone ValidationService and consolidates
 * validation logic into a dedicated handler.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class ValidationHandler
{
    /**
     * Constructor for ValidationHandler.
     *
     * @param ValidateObject     $validateHandler    Handler for object validation.
     * @param ObjectEntityMapper $objectEntityMapper Mapper for object entities.
     * @param LoggerInterface    $logger             Logger for logging operations.
     */
    public function __construct(
        private readonly ValidateObject $validateHandler,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Handles validation exceptions by delegating to ValidateObject handler.
     *
     * @param ValidationException|CustomValidationException $exception The validation exception to handle.
     *
     * @return mixed The result from the ValidateObject handler.
     *
     * @psalm-param   ValidationException|CustomValidationException $exception
     * @phpstan-param ValidationException|CustomValidationException $exception
     */
    public function handleValidationException(ValidationException|CustomValidationException $exception): mixed
    {
        return $this->validateHandler->handleValidationException($exception);
    }//end handleValidationException()

    /**
     * Validates that required fields are present in bulk objects.
     *
     * @param array $objects Array of objects to validate.
     *
     * @psalm-param   array<int, array<string, mixed>> $objects
     * @phpstan-param array<int, array<string, mixed>> $objects
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     *
     * @throws InvalidArgumentException If required fields are missing.
     */
    public function validateRequiredFields(array $objects): void
    {
        $requiredFields = ['register', 'schema'];

        foreach ($objects as $index => $object) {
            // Check if object has @self section.
            if (isset($object['@self']) === false || is_array($object['@self']) === false) {
                throw new InvalidArgumentException(
                    "Object at index {$index} is missing required '@self' section"
                );
            }

            $self = $object['@self'];

            // Check each required field.
            foreach ($requiredFields as $field) {
                if (isset($self[$field]) === false || empty($self[$field]) === true) {
                    throw new InvalidArgumentException(
                        "Object at index {$index} is missing required field '{$field}' in @self section"
                    );
                }
            }
        }
    }//end validateRequiredFields()

    /**
     * Validates all objects for a given schema.
     *
     * This method retrieves all objects for a schema and validates them
     * without actually saving. It returns arrays of valid and invalid objects.
     *
     * @param int      $schemaId     The schema ID to validate objects for.
     * @param callable $saveCallback Callback function to save/validate objects (receives: object, register, schema, uuid, rbac, multi, silent).
     *
     * @psalm-param   int $schemaId
     * @psalm-param   callable(array, int, int, string, bool, bool, bool): void $saveCallback
     * @phpstan-param int $schemaId
     * @phpstan-param callable(array, int, int, string, bool, bool, bool): void $saveCallback
     *
     * @return array Array containing 'valid' and 'invalid' objects with details.
     *
     * @psalm-return   array{valid: array<int, array{id: int, uuid: string,
     *     name: string|null, data: array<string, mixed>}>,
     *     invalid: array<int, array{id: int, uuid: string,
     *     name: string|null, data: array<string, mixed>, error: string}>}
     * @phpstan-return array{valid: array<int, array{id: int, uuid: string,
     *     name: string|null, data: array<string, mixed>}>,
     *     invalid: array<int, array{id: int, uuid: string,
     *     name: string|null, data: array<string, mixed>, error: string}>}
     */
    public function validateObjectsBySchema(int $schemaId, callable $saveCallback): array
    {
        // Use the mapper's findBySchema method to get all objects for this schema.
        // This bypasses RBAC and multi-tenancy automatically.
        $objects = $this->objectEntityMapper->findBySchema($schemaId);

        $validObjects   = [];
        $invalidObjects = [];

        foreach ($objects as $object) {
            try {
                // Get the object data for validation.
                $objectData = $object->getObject();

                // Use saveCallback with silent=true to validate without actually saving.
                // This will trigger validation and return any errors.
                $saveCallback(
                    $objectData,
                    [],
                    // Extend.
                    $object->getRegister(),
                    // Register.
                    $schemaId,
                    // Schema.
                    $object->getUuid(),
                    // UUID.
                    false,
                    // Rbac.
                    false,
                    // Multitenancy.
                    true
                    // Silent.
                );

                // If saveCallback succeeded, the object is valid.
                $validObjects[] = [
                    'id'   => $object->getId(),
                    'uuid' => $object->getUuid(),
                    'name' => $object->getName(),
                    'data' => $objectData,
                ];
            } catch (ValidationException | CustomValidationException $e) {
                // If validation failed, add to invalid objects with error details.
                $invalidObjects[] = [
                    'id'    => $object->getId(),
                    'uuid'  => $object->getUuid(),
                    'name'  => $object->getName(),
                    'data'  => $objectData,
                    'error' => $e->getMessage(),
                ];
            } catch (\Exception $e) {
                // Handle other exceptions.
                $this->logger->error(
                    'Unexpected error during validation',
                    [
                        'app'      => 'openregister',
                        'objectId' => $object->getId(),
                        'error'    => $e->getMessage(),
                    ]
                );
                $invalidObjects[] = [
                    'id'    => $object->getId(),
                    'uuid'  => $object->getUuid(),
                    'name'  => $object->getName(),
                    'data'  => $objectData,
                    'error' => 'Unexpected error: '.$e->getMessage(),
                ];
            }//end try
        }//end foreach

        return [
            'valid'   => $validObjects,
            'invalid' => $invalidObjects,
        ];
    }//end validateObjectsBySchema()

    /**
     * Validate all objects belonging to a specific schema (comprehensive version).
     *
     * This method validates all objects that belong to the specified schema against their schema definition.
     * It returns detailed validation results including valid and invalid objects with error details.
     *
     * @param int      $schemaId     The ID of the schema whose objects should be validated.
     * @param callable $saveCallback Callback to validate objects (object, register, schema, uuid, rbac, multi, silent).
     *
     * @return array Comprehensive validation results.
     *
     * @throws \Exception If the validation operation fails.
     *
     * @phpstan-return array{valid_count: int, invalid_count: int,
     *     valid_objects: array<int, array>, invalid_objects: array<int, array>,
     *     schema_id: int}
     * @psalm-return   array{valid_count: int<0, max>,
     *     invalid_count: int<0, max>,
     *     valid_objects: list<array{data: array, id: int,
     *     name: null|string, uuid: null|string}>,
     *     invalid_objects: list<array{data: array,
     *     errors: list<array{keyword: 'exception'|'validation'|mixed,
     *     message: mixed|non-falsy-string, path: 'general'|'unknown'|mixed}>,
     *     id: int, name: null|string, uuid: null|string}>, schema_id: int}
     */
    public function validateSchemaObjects(int $schemaId, callable $saveCallback): array
    {
        // Use the mapper's findBySchema method to get all objects for this schema.
        // This bypasses RBAC and multi-tenancy automatically.
        $objects = $this->objectEntityMapper->findBySchema($schemaId);

        $validObjects   = [];
        $invalidObjects = [];

        foreach ($objects as $object) {
            try {
                // Get the object data for validation.
                $objectData = $object->getObject();

                // Use saveCallback with silent=true to validate without actually saving.
                // This will trigger validation and return any errors.
                $saveCallback(
                    $objectData,
                    $object->getRegister(),
                    $schemaId,
                    $object->getUuid(),
                    false,
                    false,
                    true
                );

                // If saveCallback succeeded, the object is valid.
                $validObjects[] = [
                    'id'   => $object->getId(),
                    'uuid' => $object->getUuid(),
                    'name' => $object->getName(),
                    'data' => $objectData,
                ];
            } catch (\Exception $e) {
                // Extract validation errors from the exception.
                $errors = [];

                // Check if it's a validation exception with detailed errors.
                if ($e instanceof \OCA\OpenRegister\Exception\ValidationException) {
                    foreach ($e->getErrors() ?? [] as $error) {
                        $errors[] = [
                            'path'    => $error['path'] ?? 'unknown',
                            'message' => $error['message'] ?? $error,
                            'keyword' => $error['keyword'] ?? 'validation',
                        ];
                    }
                } else {
                    // Generic error.
                    $errors[] = [
                        'path'    => 'general',
                        'message' => 'Validation failed: '.$e->getMessage(),
                        'keyword' => 'exception',
                    ];
                }

                $invalidObjects[] = [
                    'id'     => $object->getId(),
                    'uuid'   => $object->getUuid(),
                    'name'   => $object->getName(),
                    'data'   => $objectData,
                    'errors' => $errors,
                ];
            }//end try
        }//end foreach

        return [
            'valid_count'     => count($validObjects),
            'invalid_count'   => count($invalidObjects),
            'valid_objects'   => $validObjects,
            'invalid_objects' => $invalidObjects,
            'schema_id'       => $schemaId,
        ];
    }//end validateSchemaObjects()

    /**
     * Apply inversedBy filter to query filters.
     *
     * This method resolves inversedBy relationships in filters and returns the matching object IDs.
     * It handles nested property filters (using underscore delimiters) and performs reverse lookups.
     *
     * @param array $filters Query filters to process (passed by reference).
     *
     * @return array Array of matching IDs, empty array if no matches, or null if filtered to zero results.
     *
     * @psalm-return array<never, never>
     */
    public function applyInversedByFilter(array &$filters): array|null
    {
        // This method requires additional dependencies - placeholder for now.
        // Full implementation requires SchemaMapper, ObjectService->findAll, and Dot utilities.
        return [];
    }//end applyInversedByFilter()
}//end class
