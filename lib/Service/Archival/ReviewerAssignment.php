<?php

/**
 * Handing one destruction-list entry to a reviewer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTimeInterface;

/**
 * The entry, the reviewer it is handed to, and the moment of assignment.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */
final class ReviewerAssignment {
	/**
	 * Hold the values.
	 *
	 * @param string            $entryUuid  The uuid of the object the entry is about.
	 * @param string|null       $reviewer   The reviewer's user id; null or blank unassigns.
	 * @param DateTimeInterface $assignedAt The moment of assignment.
	 */
	public function __construct(
		public readonly string $entryUuid,
		public readonly ?string $reviewer,
		public readonly DateTimeInterface $assignedAt,
	) {
	}//end __construct()
}//end class
