<?php

/**
 * BulkJobMapperInterface: what callers outside the Db component use of BulkJobMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The methods of BulkJobMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to BulkJobMapper.
 */
interface BulkJobMapperInterface {

	/**
	 * How many jobs stand in each state.
	 *
	 * One grouped query rather than one count per state, because the console
	 * asks for all of them at once and a state the instance has never reached
	 * must be absent rather than zero: the caller decides which states it
	 * names, and a missing key is honest about a state nothing has produced.
	 *
	 * @return array<string, int> State to count, for the states in use.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function countByState(): array;

	/**
	 * Create a job from an array of values, assigning a uuid and timestamps.
	 *
	 * @param array<string, mixed> $data The job values.
	 *
	 * @return BulkJob The persisted job.
	 */
	public function createFromArray(array $data): BulkJob;

	/**
	 * Find a job by its numeric id.
	 *
	 * @param int $id The job id.
	 *
	 * @return BulkJob The job.
	 *
	 * @throws DoesNotExistException When no such job exists.
	 */
	public function find(int $id): BulkJob;

	/**
	 * List every job, newest first. For the administrator's console.
	 *
	 * @param string|null $state Optional state filter.
	 * @param int|null $limit Optional page size.
	 * @param int|null $offset Optional page offset.
	 *
	 * @return BulkJob[] The jobs.
	 */
	public function findAllJobs(?string $state = null, ?int $limit = null, ?int $offset = null): array;

	/**
	 * List the jobs of one actor, newest first.
	 *
	 * @param string $startedBy The actor's uid.
	 * @param string|null $state Optional state filter.
	 * @param int|null $limit Optional page size.
	 * @param int|null $offset Optional page offset.
	 *
	 * @return BulkJob[] The actor's jobs.
	 */
	public function findByActor(string $startedBy, ?string $state = null, ?int $limit = null, ?int $offset = null): array;

	/**
	 * Read just the state of a job.
	 *
	 * The commit loop asks this between every two objects, so a cancel stops
	 * before the next object rather than at the next batch boundary (D-4).
	 * Selecting one indexed column keeps that affordable.
	 *
	 * @param int $id The job id.
	 *
	 * @return string|null The state, or null when the job is gone.
	 */
	public function readState(int $id): ?string;

	/**
	 * Persist job progress or state, refreshing the updated timestamp.
	 *
	 * @param BulkJob $job The job to persist.
	 *
	 * @return BulkJob The updated job.
	 */
	public function save(BulkJob $job): BulkJob;
}//end interface
