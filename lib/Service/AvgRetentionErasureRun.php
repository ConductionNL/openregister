<?php

/**
 * OpenRegister AvgRetentionErasureRun
 *
 * One AVG retention erasure over the overdue objects of one processing activity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The deletion stamp an AVG retention erasure applies, and what it erased and withheld.
 *
 * Built once per activity, so every object the run erases carries the same
 * stamp and the same processing activity.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class AvgRetentionErasureRun {

	/**
	 * Objects soft-deleted so far.
	 *
	 * @var int
	 */
	public int $erased = 0;

	/**
	 * Refusals of the objects left live, in candidate order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $withheld = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $deletionData The `deleted` stamp every erased object gets.
	 * @param string               $activityUuid The processing activity the erasure is recorded under.
	 */
	public function __construct(
		public readonly array $deletionData,
		public readonly string $activityUuid,
	) {
	}//end __construct()

	/**
	 * Stamp an object as erased by retention, under the run's activity.
	 *
	 * @param ObjectEntity $object The object to soft-delete.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function stamp(ObjectEntity $object): void {
		$object->setDeleted($this->deletionData);
		$object->setProcessingActivityId($this->activityUuid);
	}//end stamp()

	/**
	 * The run's result.
	 *
	 * @return array{erased: int, withheld: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function outcome(): array {
		return [
			'erased' => $this->erased,
			'withheld' => $this->withheld,
		];
	}//end outcome()
}//end class
