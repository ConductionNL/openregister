<?php

/**
 * OpenRegister ValidationOperationsHandler
 *
 * Handles administrative validation operations for all objects in the system.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Settings
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

namespace OCA\OpenRegister\Service\Settings;

use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\IAppContainer;
use Exception;

/**
 * Validation Operations Handler
 *
 * Handles administrative validation operations including:
 * - Validating all objects in the system.
 * - Generating validation reports with statistics.
 * - Aggregating validation results.
 *
 * This handler contains business logic for administrative validation
 * operations that are exposed through the settings interface.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Settings
 */
class ValidationOperationsHandler
{

    /**
     * Container for lazy loading ObjectService to break circular dependency.
     *
     * @var IAppContainer
     */
    private IAppContainer $container;

    public function __construct(
        private readonly ValidateObject $validateHandler,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger,
        IAppContainer $container
    ) {
        $this->container = $container;

    }//end __construct()

    /**
     * Get ObjectService via lazy loading to break circular dependency.
     *
     * @return null
     */
    private function getObjectService()
    {
        return null;
        // CIRCULAR FIX.
    }//end getObjectService()

    /**
     * Validate all objects in the system.
     *
     * Iterates through all objects, validates each against its schema,
     * and generates a comprehensive validation report with statistics.
     *
     * @return (((\Opis\JsonSchema\Errors\ValidationError|mixed|null|string[])[]|bool|float|int)[]|int)[]
     *
     * @throws Exception If validation operation fails.
     *
     * @psalm-return array{total_objects: int<0, max>, valid_objects: 0|1|2, invalid_objects: int, validation_errors: list<array{errors: Opis\JsonSchema\Errors\ValidationError|list{non-falsy-string}|null, object_id: mixed, object_name: mixed, register: mixed, schema: mixed}>, summary: array{validation_success_rate: 100|float, has_errors: bool, error_count: int<0, max>}}
     */
    public function validateAllObjects(): array
    {
        // Get all objects from the system.
            $allObjects = $this->getObjectService()->findAll(config: []);

        $validationResults = [
            'total_objects'     => count($allObjects),
            'valid_objects'     => 0,
            'invalid_objects'   => 0,
            'validation_errors' => [],
            'summary'           => [],
        ];

        // Validate each object.
        foreach ($allObjects as $object) {
            try {
                // Get the schema for this object.
                $schema = $this->schemaMapper->find(id: $object->getSchema());

                // Validate the object against its schema using the ValidateObject handler.
                $validationResult = $this->validateHandler->validateObject(
                    $object->getObject(),
                    schema: $schema
                );

                if ($validationResult->isValid() === true) {
                    $validationResults['valid_objects']++;
                } else {
                    $validationResults['invalid_objects']++;
                    $validationResults['validation_errors'][] = [
                        'object_id'   => $object->getUuid(),
                        'object_name' => $object->getName() ?? $object->getUuid(),
                        'register'    => $object->getRegister(),
                        'schema'      => $object->getSchema(),
                        'errors'      => $validationResult->error(),
                    ];
                }
            } catch (Exception $e) {
                $validationResults['invalid_objects']++;
                $validationResults['validation_errors'][] = [
                    'object_id'   => $object->getUuid(),
                    'object_name' => $object->getName() ?? $object->getUuid(),
                    'register'    => $object->getRegister(),
                    'schema'      => $object->getSchema(),
                    'errors'      => ['Validation failed: '.$e->getMessage()],
                ];
            }//end try
        }//end foreach

        // Create summary with validation statistics.
        $validationSuccessRate = 100;
        if ($validationResults['total_objects'] > 0) {
            $validationSuccessRate = round(
                ($validationResults['valid_objects'] / $validationResults['total_objects']) * 100,
                2
            );
        }

        $validationResults['summary'] = [
            'validation_success_rate' => $validationSuccessRate,
            'has_errors'              => $validationResults['invalid_objects'] > 0,
            'error_count'             => count($validationResults['validation_errors']),
        ];

        return $validationResults;

    }//end validateAllObjects()
}//end class
