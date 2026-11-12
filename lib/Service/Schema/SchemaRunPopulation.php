<?php

/**
 * SchemaRunPopulation — the run bookkeeping both schema run services share.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Exception\SchemaRunConcurrencyException;

/**
 * Concurrency guard, terminal state, batch read and population count of a schema run.
 *
 * The using service provides `$this->runMapper` (SchemaRunMapper),
 * `$this->runEntryMapper` (SchemaRunEntryMapper) and
 * `$this->objectService` (ObjectOperationsInterface).
 */
trait SchemaRunPopulation {

	/**
	 * Finish a run, persisting its terminal state.
	 *
	 * @param SchemaRun $run   The run.
	 * @param string    $state The terminal state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function finish(SchemaRun $run, string $state): void {
		$run->setState($state);
		$this->runMapper->save($run);

	}//end finish()

	/**
	 * Refuse a second concurrent run on the same schema.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return void
	 *
	 * @throws SchemaRunConcurrencyException When an active run exists.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function assertNoActiveRun(int $schemaId): void {
		$active = $this->runMapper->findActiveForSchema($schemaId);
		if ($active !== null) {
			throw new SchemaRunConcurrencyException(
				message: sprintf('An active run (#%d) already exists for schema %d.', $active->getId(), $schemaId)
			);
		}

	}//end assertNoActiveRun()

	/**
	 * Load the next batch of non-deleted objects after the run's processed count.
	 *
	 * @param SchemaRun $run       The run.
	 * @param int       $batchSize The batch size.
	 *
	 * @return ObjectEntity[] The objects.
	 */
	private function loadBatch(SchemaRun $run, int $batchSize): array {
		$result = $this->objectService->findAll(config: $this->batchQuery(run: $run, batchSize: $batchSize), _rbac: false, _multitenancy: false);

		return array_values(array_filter($result, static fn ($candidate) => $candidate instanceof ObjectEntity));
	}//end loadBatch()

	/**
	 * The query for the run's next batch: after the processed count, oldest id first.
	 *
	 * @param SchemaRun $run       The run.
	 * @param int       $batchSize The batch size.
	 *
	 * @return array<string, mixed> The findAll() config.
	 */
	private function batchQuery(SchemaRun $run, int $batchSize): array {
		return [
			'limit' => $batchSize,
			'offset' => $run->getProcessed(),
			'sort' => ['id' => 'ASC'],
			'filters' => [
				'register' => $run->getRegisterId(),
				'schema' => $run->getSchemaId(),
			],
		];
	}//end batchQuery()

	/**
	 * Count the non-deleted population for a schema in a register.
	 *
	 * @param int $schemaId   The schema id.
	 * @param int $registerId The register id.
	 *
	 * @return int The count, 0 when counting fails.
	 */
	private function countPopulation(int $schemaId, int $registerId): int {
		try {
			return $this->objectService->count(
				config: [
					'filters' => [
						'register' => $registerId,
						'schema' => $schemaId,
					],
				]
			);
		} catch (\Throwable $e) {
			return 0;
		}

	}//end countPopulation()

	/**
	 * The run's next batch; an empty one completes the run.
	 *
	 * @param SchemaRun $run       The run.
	 * @param int       $batchSize The batch size.
	 *
	 * @return ObjectEntity[] The objects, empty when the run is done.
	 */
	private function nextBatch(SchemaRun $run, int $batchSize): array {
		$objects = $this->loadBatch(run: $run, batchSize: $batchSize);
		if (count($objects) === 0) {
			$this->finish(run: $run, state: SchemaRun::STATE_COMPLETED);
		}

		return $objects;
	}//end nextBatch()

	/**
	 * Record one per-object entry of a run.
	 *
	 * @param SchemaRun            $run   The run.
	 * @param array<string, mixed> $entry The entry fields after `runId`.
	 *
	 * @return void
	 */
	private function recordEntry(SchemaRun $run, array $entry): void {
		$this->runEntryMapper->createFromArray(['runId' => $run->getId()] + $entry);

	}//end recordEntry()

	/**
	 * Close a batch that leaves work for the next one, persisting the run.
	 *
	 * @param SchemaRunBatch $batch The batch.
	 *
	 * @return bool Always true: more work may remain.
	 */
	private function saveBatch(SchemaRunBatch $batch): bool {
		$batch->close();
		$this->runMapper->save($batch->run);

		return true;
	}//end saveBatch()

	/**
	 * Close a batch that halts its run as failed.
	 *
	 * @param SchemaRunBatch $batch The batch.
	 *
	 * @return bool Always false: the run is done.
	 */
	private function haltBatch(SchemaRunBatch $batch): bool {
		$batch->close();
		$this->finish(run: $batch->run, state: SchemaRun::STATE_FAILED);

		return false;
	}//end haltBatch()
}//end trait
