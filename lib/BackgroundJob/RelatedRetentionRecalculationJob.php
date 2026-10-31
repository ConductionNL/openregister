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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\DependantRetentionIndex;
use OCA\OpenRegister\Service\RetentionService;
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
	 * @param ITimeFactory                $time           The clock
	 * @param MagicMapper                 $objectMapper   Reads and writes the records
	 * @param SchemaMapper                $schemaMapper   Resolves the source record's schema
	 * @param RegisterMapper              $registerMapper Finds the registers a dependant schema lives in
	 * @param DependantRetentionIndex     $index          Knows the dependant schemas
	 * @param ArchiveActionDateCalculator $calculator     Reads the reference a dependant holds
	 * @param RetentionService            $retention      Recalculates one record
	 * @param LoggerInterface             $logger         The logger
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A DI constructor; each collaborator is used
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly MagicMapper $objectMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly DependantRetentionIndex $index,
		private readonly ArchiveActionDateCalculator $calculator,
		private readonly RetentionService $retention,
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
		$source = $this->objectMapper->find(identifier: $uuid, _rbac: false, _multitenancy: false);
		$sourceSchema = $this->schemaMapper->find(id: (string)$source->getSchema(), _rbac: false, _multitenancy: false);

		$changed = 0;
		foreach ($this->index->dependantsOf(source: $sourceSchema) as $dependant) {
			$schema = $dependant['schema'];
			foreach ($this->registerMapper->getAllRegisterIdsWithSchema((int)$schema->getId()) as $registerId) {
				$register = $this->registerMapper->find(id: $registerId, _rbac: false, _multitenancy: false);
				$rows = $this->objectMapper->findByRelationBatchInSchema(
					uuids: [$uuid],
					schemaId: (int)$schema->getId(),
					registerId: (int)$registerId,
					fieldName: $dependant['relation'],
					additionalFieldNames: [$dependant['relation']]
				);

				foreach (array_chunk($rows, self::BATCH) as $batch) {
					$changed += $this->recalculateBatch(
						batch: $batch,
						uuid: $uuid,
						relation: $dependant['relation'],
						register: $register,
						schema: $schema
					);
				}
			}//end foreach
		}//end foreach

		return $changed;
	}//end recalculateDependantsOf()

	/**
	 * Recalculate one batch of candidate dependants.
	 *
	 * A candidate found through the relation index may point at the source
	 * from another property; only one whose derivation reference is the
	 * source is recalculated.
	 *
	 * @param list<ObjectEntity> $batch    The candidates
	 * @param string             $uuid     The source record's uuid
	 * @param string             $relation The property holding the reference
	 * @param Register           $register The register the candidates live in
	 * @param Schema             $schema   Their schema
	 *
	 * @return int How many got a new destruction date
	 */
	private function recalculateBatch(array $batch, string $uuid, string $relation, Register $register, Schema $schema): int {
		$changed = 0;
		foreach ($batch as $object) {
			$reference = $this->calculator->referenceOf(value: (($object->getObject() ?? [])[$relation] ?? null));
			if ($reference === null || str_ends_with($reference, $uuid) === false) {
				continue;
			}

			try {
				if ($this->retention->followSourceDate(object: $object, schema: $schema) === false) {
					continue;
				}

				$this->objectMapper->update(entity: $object, register: $register, schema: $schema);
				$changed++;
			} catch (Throwable $failure) {
				$this->logger->warning(
					sprintf(
						'[RelatedRetentionRecalculationJob] could not recalculate %s from %s: %s',
						(string)$object->getUuid(),
						$uuid,
						$failure->getMessage()
					)
				);
			}//end try
		}//end foreach

		return $changed;
	}//end recalculateBatch()
}//end class
