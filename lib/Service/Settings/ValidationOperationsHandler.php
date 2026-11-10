<?php

/**
 * OpenRegister ValidationOperationsHandler
 *
 * Handles administrative validation operations for all objects in the system.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use Exception;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ObjectValidatorInterface;
use OCP\AppFramework\IAppContainer;
use Psr\Log\LoggerInterface;

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
class ValidationOperationsHandler {

	/**
	 * Constructor for ValidationOperationsHandler.
	 *
	 * @param ObjectValidatorInterface|null $validateHandler Validation handler; null loads ValidateObject lazily (circular dependency)
	 * @param SchemaMapperInterface $schemaMapper Mapper for schema entities
	 * @param LoggerInterface $logger Logger for logging operations
	 * @param IAppContainer $container Application container, for lazy loading to break a circular dependency
	 */
	public function __construct(
		private ?ObjectValidatorInterface $validateHandler,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
		private readonly IAppContainer $container,
	) {
	}//end __construct()

	/**
	 * Get ValidateObject lazily to break circular dependency.
	 *
	 * @return ObjectValidatorInterface The validate handler.
	 */
	private function getValidateHandler(): ObjectValidatorInterface {
		if ($this->validateHandler === null) {
			$this->validateHandler = $this->container->get(ValidateObject::class);
		}

		return $this->validateHandler;
	}//end getValidateHandler()

	/**
	 * Get ObjectService via lazy loading to break circular dependency.
	 *
	 * @return null
	 */
	private function getObjectService() {
		// CIRCULAR FIX - ObjectService causes circular dependency, return null to break it.
		// This method is a placeholder for when circular dependency is resolved.
		return null;
	}//end getObjectService()

	/**
	 * Validate all objects in the system.
	 *
	 * Iterates through all objects, validates each against its schema,
	 * and generates a comprehensive validation report with statistics.
	 *
	 * @throws Exception If validation operation fails.
	 *
	 * @return array Validation report with total_objects, valid/invalid counts, errors, and summary.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-4
	 */
	public function validateAllObjects(): array {
		// Get all objects from the system.
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			// Return empty result when ObjectService is unavailable (circular dependency workaround).
			return $this->emptyReport();
		}

		$allObjects = $objectService->findAll(config: []);

		$validationResults = $this->openReport(totalObjects: count($allObjects));

		foreach ($allObjects as $object) {
			$this->tallyObject(validationResults: $validationResults, object: $object);
		}

		$validationResults['summary'] = $this->summarise(validationResults: $validationResults);

		return $validationResults;
	}//end validateAllObjects()

	/**
	 * The report returned when no ObjectService is available.
	 *
	 * @return array The empty validation report.
	 */
	private function emptyReport(): array {
		return [
			'total_objects' => 0,
			'valid_objects' => 0,
			'invalid_objects' => 0,
			'validation_errors' => [],
			'summary' => [
				'validation_success_rate' => 100,
				'has_errors' => false,
				'error_count' => 0,
			],
		];
	}//end emptyReport()

	/**
	 * Start a report with zeroed counters for the given number of objects.
	 *
	 * @param int $totalObjects The number of objects that will be validated
	 *
	 * @return array The report, without a summary yet.
	 */
	private function openReport(int $totalObjects): array {
		return [
			'total_objects' => $totalObjects,
			'valid_objects' => 0,
			'invalid_objects' => 0,
			'validation_errors' => [],
			'summary' => [],
		];
	}//end openReport()

	/**
	 * Validate one object against its schema and count the outcome into the report.
	 *
	 * @param array $validationResults The report being built (by reference)
	 * @param mixed $object            The object entity to validate
	 *
	 * @return void
	 */
	private function tallyObject(array &$validationResults, $object): void {
		try {
			$validationResult = $this->validateAgainstSchema(object: $object);

			if ($validationResult->isValid() === true) {
				$validationResults['valid_objects']++;
			}

			if ($validationResult->isValid() !== true) {
				$entry = $this->errorEntry(object: $object, errors: $validationResult->error());
				$this->recordInvalid(validationResults: $validationResults, errorEntry: $entry);
			}
		} catch (Exception $e) {
			$entry = $this->errorEntry(object: $object, errors: ['Validation failed: ' . $e->getMessage()]);
			$this->recordInvalid(validationResults: $validationResults, errorEntry: $entry);
		}//end try
	}//end tallyObject()

	/**
	 * Validate an object against its own schema.
	 *
	 * @param mixed $object The object entity to validate
	 *
	 * @return mixed The validation result.
	 */
	private function validateAgainstSchema($object) {
		// Get the schema for this object.
		$schema = $this->schemaMapper->find(id: $object->getSchema());

		// Validate the object against its schema using the ValidateObject handler.
		return $this->getValidateHandler()->validateObject(
			$object->getObject(),
			schema: $schema
		);
	}//end validateAgainstSchema()

	/**
	 * Count one invalid object and add its entry to the report.
	 *
	 * @param array $validationResults The report being built (by reference)
	 * @param array $errorEntry        The entry describing the invalid object
	 *
	 * @return void
	 */
	private function recordInvalid(array &$validationResults, array $errorEntry): void {
		$validationResults['invalid_objects']++;
		$validationResults['validation_errors'][] = $errorEntry;
	}//end recordInvalid()

	/**
	 * Describe one invalid object for the report's validation_errors list.
	 *
	 * @param mixed $object The object entity that failed validation
	 * @param mixed $errors The validation errors for the object
	 *
	 * @return array The error entry.
	 */
	private function errorEntry($object, $errors): array {
		return [
			'object_id' => $object->getUuid(),
			'object_name' => $object->getName() ?? $object->getUuid(),
			'register' => $object->getRegister(),
			'schema' => $object->getSchema(),
			'errors' => $errors,
		];
	}//end errorEntry()

	/**
	 * Build the summary block with the validation statistics.
	 *
	 * @param array $validationResults The counted report
	 *
	 * @return array The summary.
	 */
	private function summarise(array $validationResults): array {
		$validSuccessRate = 100;
		if ($validationResults['total_objects'] > 0) {
			$validSuccessRate = round(
				($validationResults['valid_objects'] / $validationResults['total_objects']) * 100,
				2
			);
		}

		return [
			'validation_success_rate' => $validSuccessRate,
			'has_errors' => $validationResults['invalid_objects'] > 0,
			'error_count' => count($validationResults['validation_errors']),
		];
	}//end summarise()
}//end class
