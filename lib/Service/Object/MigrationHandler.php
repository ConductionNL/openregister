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
use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCA\OpenRegister\Service\ObjectMigration;
use OCA\OpenRegister\Db\ObjectEntity;
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
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One collaborator per migration step.
 */
class MigrationHandler {
	/**
	 * Constructor for MigrationHandler.
	 *
	 * @param MigrationStorage $storage Reads, moves and records the objects.
	 * @param MigrationMapping $mapping Resolves the addresses and maps the data.
	 * @param LoggerInterface  $logger  Logger for logging operations.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly MigrationStorage $storage,
		private readonly MigrationMapping $mapping,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Migrate objects between registers and/or schemas.
	 *
	 * This method migrates multiple objects from one register/schema combination
	 * to another register/schema combination with property mapping.
	 *
	 * @param ObjectMigration $migration The source and target register and schema (each an ID or slug), the
	 *                                   object IDs to migrate and the mapping (target property => source property).
	 *
	 * @return array Migration report with success status, statistics, details, warnings, and errors.
	 *
	 * @throws OcpDoesNotExistException If register or schema not found.
	 * @throws InvalidArgumentException If invalid parameters provided.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function migrateObjects(ObjectMigration $migration): array {
		$run          = new MigrationRun(
			source: [$migration->source->register, $migration->source->schema],
			target: [$migration->target->register, $migration->target->schema]
		);
		$run->mapping = $migration->mapping;

		return $this->migrate(run: $run, objectIds: $migration->objectIds);
	}//end migrateObjects()

	/**
	 * Migrate the requested objects along one route and report each of them.
	 *
	 * @param MigrationRun $run       The route, the mapping and the report.
	 * @param array        $objectIds Array of object IDs to migrate.
	 *
	 * @return array Migration report with success status, statistics, details, warnings, and errors.
	 *
	 * @throws OcpDoesNotExistException If register or schema not found.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function migrate(MigrationRun $run, array $objectIds): array {
		$this->loadSides(run: $run);
		if ($run->source->isLoaded() === false || $run->target->isLoaded() === false) {
			throw new OcpDoesNotExistException('One or more registers/schemas not found');
		}

		$sourceObjects = $this->storage->objectMapper->findMultiple($objectIds);

		// An id stays here until an object with that uuid or id is returned, so whatever is
		// left was not found.
		$remainingObjectIds = $objectIds;
		foreach ($sourceObjects as $sourceObject) {
			$remainingObjectIds = $this->withoutFound(objectIds: $remainingObjectIds, found: $sourceObject);
			$this->migrateOne(sourceObject: $sourceObject, run: $run);
		}

		foreach ($remainingObjectIds as $notFoundId) {
			$run->notFound(notFoundId: $notFoundId);
		}

		return $run->report();
	}//end migrate()

	/**
	 * The requested ids that are neither the uuid nor the id of a found object.
	 *
	 * @param array $objectIds The ids still unaccounted for.
	 * @param mixed $found     The object found.
	 *
	 * @return array The ids still unaccounted for, keys kept
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function withoutFound(array $objectIds, mixed $found): array {
		return array_filter(
			$objectIds,
			// Compare as strings: ids arrive from the request as strings, getId() is an int.
			fn ($id) => (string) $id !== $found->getUuid() && (string) $id !== (string) $found->getId()
		);
	}//end withoutFound()

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
			$side->registerEntity = $this->mapping->utilityHandler->normalizeEntity(entity: $side->register, type: 'register');
			$side->schemaEntity = $this->mapping->utilityHandler->normalizeEntity(entity: $side->schema, type: 'schema');
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

			$stored = $this->storedObject(sourceObject: $sourceObject, run: $run);
			$this->assertMayLeave(stored: $stored);
			$sourceData = $stored->getObject();
			$mappedData = $this->mapping->dataManipHandler->mapObjectProperties(
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

			$before = clone $stored;

			// Relations are keyed by property, so the mapping decides which survive and under which name.
			$stored->setObject($mappedData);
			$stored->setRelations(
				$this->mapping->relationScanner->scanForRelations(data: $mappedData, schema: $run->target->schemaEntity)
			);
			$move = $this->moveOf(stored: $stored, run: $run)->storedAs(stored: $before);
			$objectDetail['newObjectId'] = $this->moveToTarget(move: $move, run: $run);
			$this->recordMapping(before: $before, after: $stored);

			$objectDetail['success'] = true;
			$run->migrated();
		} catch (Exception $e) {
			$objectDetail['error'] = $e->getMessage();
			$run->failed(objectId: $objectId, reason: $e->getMessage());
		}//end try

		$run->addDetail(detail: $objectDetail);
	}//end migrateOne()

	/**
	 * The object as stored in the source table, with its owner, organisation and relations.
	 *
	 * The batch load fills in the data and the address only, and whatever is not on the entity
	 * is not written at the target. Access was decided by the caller, so it is not applied again.
	 *
	 * @param ObjectEntity $sourceObject The object as the batch load returned it.
	 * @param MigrationRun $run          The migration.
	 *
	 * @return ObjectEntity The full object.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function storedObject(ObjectEntity $sourceObject, MigrationRun $run): ObjectEntity {
		return $this->storage->objectMapper->findInRegisterSchemaTable(
			identifier: (string)$sourceObject->getUuid(),
			register: $run->source->registerEntity,
			schema: $run->source->schemaEntity,
			_rbac: false,
			_multitenancy: false
		);
	}//end storedObject()

	/**
	 * Refuse an object that has to stay, asked before the mapping changes what it holds.
	 *
	 * A migration names no actor, so it holds no lock.
	 *
	 * @param ObjectEntity $stored The object as stored in the source table.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the object has to stay.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function assertMayLeave(ObjectEntity $stored): void {
		$kept = $this->storage->mover->keptReason(object: $stored);
		if ($kept !== null) {
			throw new InvalidArgumentException($kept);
		}
	}//end assertMayLeave()

	/**
	 * The move of one object from the migration's source to its target.
	 *
	 * The audit entry names the caller from the session, so no actor is given.
	 *
	 * @param ObjectEntity $stored The object, carrying its mapped data.
	 * @param MigrationRun $run    The migration.
	 *
	 * @return ObjectMove The move.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function moveOf(ObjectEntity $stored, MigrationRun $run): ObjectMove {
		return new ObjectMove(
			object: $stored,
			source: new MoveAddress(register: $run->source->registerEntity, schema: $run->source->schemaEntity),
			target: new MoveAddress(register: $run->target->registerEntity, schema: $run->target->schemaEntity),
			actor: ''
		);
	}//end moveOf()

	/**
	 * Move the object with its mapped data: one row at the target, none left in the source.
	 *
	 * The move names the row as stored, so the update guards judge what the mapping changed.
	 *
	 * @param ObjectMove   $move The move.
	 * @param MigrationRun $run  The migration.
	 *
	 * @return string The uuid of the moved object.
	 *
	 * @throws InvalidArgumentException When the object was not moved.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function moveToTarget(ObjectMove $move, MigrationRun $run): string {
		$outcome = $this->storage->mover->perform(move: $move);

		if ($outcome['moved'] === false) {
			throw new InvalidArgumentException(implode(' ', $outcome['errors']));
		}

		foreach ($outcome['errors'] as $warning) {
			$run->warn(warning: $outcome['uuid'] . ': ' . $warning);
		}

		return $outcome['uuid'];
	}//end moveToTarget()

	/**
	 * Record what the mapping changed: the move's own audit entry only names the two addresses.
	 *
	 * The object has moved by now, so a failed entry is logged and does not fail the object.
	 *
	 * @param ObjectEntity $before The object as stored in the source.
	 * @param ObjectEntity $after  The object at the target.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function recordMapping(ObjectEntity $before, ObjectEntity $after): void {
		try {
			$this->storage->auditTrail->recordChange(change: new AuditedChange(old: $before, new: $after, action: 'update'));
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[MigrationHandler] the mapping of ' . (string)$after->getUuid() . ' could not be recorded: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end recordMapping()

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
