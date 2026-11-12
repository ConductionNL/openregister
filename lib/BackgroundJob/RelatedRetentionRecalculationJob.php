<?php

/**
 * OpenRegister RelatedRetentionRecalculationJob
 *
 * Recalculates the destruction date of every record that reads its source
 * date off one changed record, through a relation-based derivation method.
 * A client's end date moves, and its contact persons follow.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\Support\RelatedRetentionLookup;
use OCA\OpenRegister\BackgroundJob\Support\RelatedRetentionRules;
use OCA\OpenRegister\BackgroundJob\Support\RetentionDependantPlace;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Follows one changed record's date into the records that depend on it.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Reads three mappers and two archival services by nature
 */
class RelatedRetentionRecalculationJob extends QueuedJob {

	/**
	 * How many dependants are recalculated before the next batch is logged.
	 *
	 * @var int
	 */
	public const BATCH = 500;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory           $time   The clock
	 * @param RelatedRetentionLookup $lookup Object, schema and register mappers
	 * @param RelatedRetentionRules  $rules  Dependant index, reference calculator and retention service
	 * @param LoggerInterface        $logger The logger
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly RelatedRetentionLookup $lookup,
		private readonly RelatedRetentionRules $rules,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Recalculate the dependants of one source record.
	 *
	 * @param mixed $argument `['uuid' => string]`, the record whose date changed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	protected function run($argument): void {
		$uuid = '';
		if (is_array($argument) === true) {
			$uuid = trim((string)($argument['uuid'] ?? ''));
		}

		if ($uuid === '') {
			$this->logger->warning('[RelatedRetentionRecalculationJob] queued without a uuid; nothing to do');
			return;
		}

		try {
			$this->recalculateDependantsOf(uuid: $uuid);
		} catch (Throwable $failure) {
			$this->logger->error(
				sprintf('[RelatedRetentionRecalculationJob] dependants of %s failed: %s', $uuid, $failure->getMessage())
			);
		}
	}//end run()

	/**
	 * Recalculate and store every dependant of a source record.
	 *
	 * @param string $uuid The source record's uuid
	 *
	 * @return int How many dependants got a new destruction date
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function recalculateDependantsOf(string $uuid): int {
		$this->lookup->objectMapper->invalidateTableMemos();
		$source = $this->lookup->objectMapper->find(identifier: $uuid, _rbac: false, _multitenancy: false);
		$sourceSchema = $this->lookup->schemaMapper->find(id: (string)$source->getSchema(), _rbac: false, _multitenancy: false);

		$changed = 0;
		foreach ($this->rules->index->dependantsOf(source: $sourceSchema) as $dependant) {
			$schema = $dependant['schema'];
			foreach ($this->lookup->registerMapper->getAllRegisterIdsWithSchema((int)$schema->getId()) as $registerId) {
				$register = $this->lookup->registerMapper->find(id: $registerId, _rbac: false, _multitenancy: false);
				$rows = $this->lookup->objectMapper->findByRelationBatchInSchema(
					uuids: [$uuid],
					schemaId: (int)$schema->getId(),
					registerId: (int)$registerId,
					fieldName: $dependant['relation'],
					additionalFieldNames: [$dependant['relation']]
				);

				foreach (array_chunk($rows, self::BATCH) as $batch) {
					$changed += $this->recalculateBatch(
						batch: $batch,
						place: new RetentionDependantPlace(sourceUuid: $uuid, relation: $dependant['relation'], register: $register, schema: $schema)
					);
				}
			}//end foreach
		}//end foreach

		return $changed;
	}//end recalculateDependantsOf()

	/**
	 * Recalculate one batch of candidate dependants.
	 *
	 * @param list<ObjectEntity>      $batch The candidates
	 * @param RetentionDependantPlace $place The source they refer to and where they live
	 *
	 * @return int How many got a new destruction date
	 */
	private function recalculateBatch(array $batch, RetentionDependantPlace $place): int {
		$changed = 0;
		foreach ($batch as $object) {
			if ($this->recalculateOne(object: $object, place: $place) === true) {
				$changed++;
			}
		}

		return $changed;
	}//end recalculateBatch()

	/**
	 * Recalculate one candidate dependant; a failure is logged, not thrown.
	 *
	 * A candidate found through the relation index may point at the source
	 * from another property; only one whose derivation reference is the
	 * source is recalculated.
	 *
	 * @param ObjectEntity            $object The candidate
	 * @param RetentionDependantPlace $place  The source it refers to and where it lives
	 *
	 * @return bool True when it got a new destruction date
	 */
	private function recalculateOne(ObjectEntity $object, RetentionDependantPlace $place): bool {
		$reference = $this->rules->calculator->referenceOf(value: (($object->getObject() ?? [])[$place->relation] ?? null));
		if ($reference === null || str_ends_with($reference, $place->sourceUuid) === false) {
			return false;
		}

		try {
			return $this->storeFollowed(object: $object, place: $place);
		} catch (Throwable $failure) {
			$this->logger->warning(
				sprintf(
					'[RelatedRetentionRecalculationJob] could not recalculate %s from %s: %s',
					(string)$object->getUuid(),
					$place->sourceUuid,
					$failure->getMessage()
				)
			);

			return false;
		}
	}//end recalculateOne()

	/**
	 * Re-read one dependant whole, let its destruction date follow the source and store it when it moved.
	 *
	 * @param ObjectEntity            $object The candidate, as the relation lookup filled it
	 * @param RetentionDependantPlace $place  Where it lives
	 *
	 * @return bool True when it got a new destruction date
	 */
	private function storeFollowed(ObjectEntity $object, RetentionDependantPlace $place): bool {
		// The relation lookup fills only name and data; an update writes the whole row.
		$stored = $this->lookup->objectMapper->find(
			identifier: (string)$object->getUuid(),
			register: $place->register,
			schema: $place->schema,
			_rbac: false,
			_multitenancy: false
		);
		if ($this->rules->retention->followSourceDate(object: $stored, schema: $place->schema) === false) {
			return false;
		}

		$this->lookup->objectMapper->update(entity: $stored, register: $place->register, schema: $place->schema);

		return true;
	}//end storeFollowed()
}//end class
