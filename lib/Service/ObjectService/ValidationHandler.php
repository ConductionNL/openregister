<?php

/**
 * ValidationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\ObjectService;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Objects\ValidateObject;
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
 * @license  AGPL-3.0
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
     * @return void
     *
     * @throws InvalidArgumentException If required fields are missing.
     *
     * @psalm-param    array<int, array<string, mixed>> $objects
     * @phpstan-param  array<int, array<string, mixed>> $objects
     * @psalm-return   void
     * @phpstan-return void
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
     * @return array Array containing 'valid' and 'invalid' objects with details.
     *
     * @psalm-param    int $schemaId
     * @phpstan-param  int $schemaId
     * @psalm-param    callable(array, int, int, string, bool, bool, bool): void $saveCallback
     * @phpstan-param  callable(array, int, int, string, bool, bool, bool): void $saveCallback
     * @psalm-return   array{valid: array<int, array{id: int, uuid: string, name: string|null, data: array<string, mixed>}>, invalid: array<int, array{id: int, uuid: string, name: string|null, data: array<string, mixed>, error: string}>}
     * @phpstan-return array{valid: array<int, array{id: int, uuid: string, name: string|null, data: array<string, mixed>}>, invalid: array<int, array{id: int, uuid: string, name: string|null, data: array<string, mixed>, error: string}>}
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
                    $object->getRegister(),
                    $schemaId,
                    $object->getUuid(),
                    false,
                    // rbac
                    false,
                    // multi
                    true
                    // silent
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
}//end class
