<?php

/**
 * OpenRegister DSAR Erasure Run
 *
 * The outcome of one right-to-erasure request across the matched objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The soft-delete stamp and processing activity applied to each erased object, and
 * the three buckets every matched object lands in: erased, failed or withheld.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
 */
final class DsarErasureRun {

	/**
	 * Objects erased: uuid, register, schema.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $erased = [];

	/**
	 * Matches that could not be erased, with the error.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $failed = [];

	/**
	 * Refusals for records held under an archival obligation or legal hold.
	 *
	 * @var array<int, mixed>
	 */
	public array $withheld = [];

	/**
	 * The configured DSAR processing activity, null when none is set.
	 *
	 * @var string|null
	 */
	public ?string $activityUuid = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $deletionData Soft-delete metadata to stamp on each erased object.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
	 */
	public function __construct(
		public readonly array $deletionData = [],
	) {
	}//end __construct()

	/**
	 * Stamp an object as soft-deleted under this run, with the DSAR activity when set.
	 *
	 * @param ObjectEntity $object The object to erase.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
	 */
	public function stamp(ObjectEntity $object): void {
		$object->setDeleted($this->deletionData);
		if ($this->activityUuid !== null) {
			$object->setProcessingActivityId($this->activityUuid);
		}
	}//end stamp()

	/**
	 * Record a match that was not erased.
	 *
	 * @param array<string, mixed> $entry The match (object_id / object_uuid).
	 * @param string               $error Why it was not erased.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
	 */
	public function fail(array $entry, string $error): void {
		$this->failed[] = ['object' => $entry, 'error' => $error];
	}//end fail()

	/**
	 * The buckets and the completion figures. A failed or withheld match makes the
	 * erasure incomplete: the row is still there.
	 *
	 * @return array<string, mixed> erased, failed, withheld, complete, failedCount, withheldCount.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
	 */
	public function outcome(): array {
		return [
			'erased' => $this->erased,
			'failed' => $this->failed,
			'withheld' => $this->withheld,
			'complete' => ($this->failed === [] && $this->withheld === []),
			'failedCount' => count($this->failed),
			'withheldCount' => count($this->withheld),
		];
	}//end outcome()
}//end class
