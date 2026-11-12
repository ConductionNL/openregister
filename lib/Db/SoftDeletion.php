<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateInterval;
use DateTime;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;

/**
 * Why an object is soft-deleted and how many days it stays recoverable.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class SoftDeletion {

	/**
	 * Constructor.
	 *
	 * @param string|null $reason        Reason for deletion.
	 * @param int|null    $retentionDays Retention period in days; null or below 1 takes the default window.
	 */
	public function __construct(
		public readonly ?string $reason=null,
		public readonly ?int $retentionDays=30,
	) {
	}//end __construct()

	/**
	 * The recovery window in days.
	 *
	 * A non-positive retention is not a window, so the default applies.
	 *
	 * @return int The days.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function days(): int {
		if ($this->retentionDays === null || $this->retentionDays < 1) {
			return DeletionWindowService::DEFAULT_RETENTION_DAYS;
		}

		return $this->retentionDays;
	}//end days()

	/**
	 * The deletion metadata an object stores when deleted by a user at a moment.
	 *
	 * @param string   $userId The deleting user.
	 * @param DateTime $now    The moment of deletion.
	 *
	 * @return array<string, mixed> The deleted block.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function metadataFor(string $userId, DateTime $now): array {
		$days = $this->days();
		$purgeDate = clone $now;
		$purgeDate->add(new DateInterval('P' . (string)$days . 'D'));

		return [
			'deleted' => $now->format('c'),
			'deletedAt' => $now->format('c'),
			'deletedBy' => $userId,
			'deletedReason' => $this->reason,
			'retentionPeriod' => $days,
			'purgeDate' => $purgeDate->format('c'),
			'destroyableFrom' => $purgeDate->format('c'),
		];
	}//end metadataFor()
}//end class
