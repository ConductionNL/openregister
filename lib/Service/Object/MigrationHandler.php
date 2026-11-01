<?php

/**
 * MigrationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException as OcpDoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Handles object migration between schemas and registers.
 *
 * This handler is responsible for:
 * - Migrating objects from one schema to another
 * - Moving objects between registers
 * - Preserving relationships during migration
 * - Batch migration operations
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class MigrationHandler {
	/**
	 * Constructor for MigrationHandler.
	 *
	 * @param MagicMapper $objectMapper Mapper for object entities.
	 * @param SchemaMapper $schemaMapper Mapper for schema entities.
	 * @param RegisterMapper $registerMapper Mapper for register entities.
	 * @param SaveObject $saveHandler Handler for saving objects.
	 * @param UtilityHandler $utilityHandler Handler for utility operations.
	 * @param DataManipulationHandler $dataManipHandler Handler for data manipulation.
	 * @param LoggerInterface $logger Logger for logging operations.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly MagicMapper $objectMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly SaveObject $saveHandler,
		private readonly UtilityHandler $utilityHandler,
		private readonly DataManipulationHandler $dataManipHandler,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Migrate objects between registers and/or schemas.
	 *
	 * This method migrates multiple objects from one register/schema combination
	 * to another register/schema combination with property mapping.
	 *
	 * @param string|int $sourceRegister The source register ID or slug.
	 * @param string|int $sourceSchema The source schema ID or slug.
	 * @param string|int $targetRegister The target register ID or slug.
	 * @param string|int $targetSchema The target schema ID or slug.
	 * @param array $objectIds Array of object IDs to migrate.
	 * @param array $mapping Simple mapping where keys are target properties, values are source properties.
	 *
	 * @return array Migration report with success status, statistics, details, warnings, and errors.
	 *
	 * @throws OcpDoesNotExistException If register or schema not found.
	 * @throws InvalidArgumentException If invalid parameters provided.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function migrateObjects(
		string|int $sourceRegister,
		string|int $sourceSchema,
		string|int $targetRegister,
		string|int $targetSchema,
		array $objectIds,
		array $mapping,
	): array {
		$run = new MigrationRun(
			source: [$sourceRegister, $sourceSchema],
			target: [$targetRegister, $targetSchema]
		);
		$run->mapping = $mapping;

		$this->loadSides(run: $run);
		if ($run->source->isLoaded() === false || $run->target->isLoaded() === false) {
			throw new OcpDoesNotExistException('One or more registers/schemas not found');
		}

		$sourceObjects = $this->objectMapper->findMultiple($objectIds);

		// An id stays here until an object with that uuid or id is returned, so whatever is
		// left was not found.
		$remainingObjectIds = $objectIds;
		foreach ($sourceObjects as $sourceObject) {
			$remainingObjectIds = array_filter(
				$remainingObjectIds,
				fn ($id) => $id !== $sourceObject->getUuid() && $id !== $sourceObject->getId()
			);
			$this->migrateOne(sourceObject: $sourceObject, run: $run);
		}

		foreach ($remainingObjectIds as $notFoundId) {
			$run->notFound(notFoundId: $notFoundId);
		}

		return $run->report();
	}//end migrateObjects()

	/**
	 * Load the source register and schema, then the target register and schema.
	 *
	 * @param MigrationRun $run The migration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function loadSides(MigrationRun $run): void {
		foreach ([$run->source, $run->target] as $side) {
			$side->registerEntity = $this->utilityHandler->normalizeEntity(entity: $side->register, type: 'register');
			$side->schemaEntity = $this->utilityHandler->normalizeEntity(entity: $side->schema, type: 'schema');
		}
	}//end loadSides()

	/**
	 * Move one object to the target register and schema with its mapped data, and report it.
	 *
	 * Any failure is recorded on the run and the next object is tried.
	 *
	 * @param ObjectEntity $sourceObject The object as loaded.
	 * @param MigrationRun $run          The migration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function migrateOne(ObjectEntity $sourceObject, MigrationRun $run): void {
		$objectId = $sourceObject->getUuid();
		$objectDetail = [
			'objectId' => $objectId,
			'objectTitle' => null,
			'success' => false,
			'error' => null,
		];

		try {
			$objectDetail['objectTitle'] = $sourceObject->getName() ?? $sourceObject->getUuid();
			$this->assertInSource(sourceObject: $sourceObject, run: $run);

			$sourceData = $sourceObject->getObject();
			$mappedData = $this->dataManipHandler->mapObjectProperties(
				sourceData: $sourceData,
				mapping: $run->mapping
			);
			$run->countMapping(sourceData: $sourceData, mappedData: $mappedData);

			$this->logger->debug(
				message: '[MigrationHandler] Object properties mapped',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'mappedData' => $mappedData,
				]
			);

			// Files and relations stay attached to the object, so moving it moves them.
			$sourceObject->setRegister($run->target->registerEntity->getId());
			$sourceObject->setSchema($run->target->schemaEntity->getId());
			$sourceObject->setObject($mappedData);
			$savedObject = $this->objectMapper->update($sourceObject);

			$objectDetail['success'] = true;
			$objectDetail['newObjectId'] = $savedObject->getUuid();
			$run->migrated();
		} catch (Exception $e) {
			$objectDetail['error'] = $e->getMessage();
			$run->failed(objectId: $objectId, reason: $e->getMessage());
		}//end try

		$run->addDetail(detail: $objectDetail);
	}//end migrateOne()

	/**
	 * Refuse an object that does not live in the requested source register and schema.
	 *
	 * The stored register and schema are compared with the ids of the loaded source
	 * entities, so a source named by slug matches too.
	 *
	 * @param ObjectEntity $sourceObject The object as loaded.
	 * @param MigrationRun $run          The migration.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the object lives elsewhere.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function assertInSource(ObjectEntity $sourceObject, MigrationRun $run): void {
		$source = $run->source;
		$actualRegister = $sourceObject->getRegister();
		$actualSchema = $sourceObject->getSchema();
		if ((int)$actualRegister === (int)$source->registerEntity->getId()
			&& (int)$actualSchema === (int)$source->schemaEntity->getId()
		) {
			return;
		}

		$expected = "register='{$source->register}', schema='{$source->schema}'";
		$actual = "register='{$actualRegister}', schema='{$actualSchema}'";
		throw new InvalidArgumentException(
			sprintf(
				'Object %s does not belong to the specified source register/schema. Expected: %s. Actual: %s',
				$sourceObject->getUuid(),
				$expected,
				$actual
			)
		);
	}//end assertInSource()

}//end class
