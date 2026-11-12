<?php

/**
 * Where one candidate stands in the winner comparison: its tier rank and its date.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survivorship;

use DateTimeImmutable;

/**
 * A candidate's standing when picking a winner: its tier rank and its parsed
 * date. The standing before any winner is rank -1 with no date, which every
 * eligible rank (>= 0) beats.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
 */
final readonly class CandidateStanding {

	/**
	 * Bundle the standing.
	 *
	 * @param int                    $rank The tier rank, -1 before any winner.
	 * @param DateTimeImmutable|null $date The parsed date, null when absent.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	public function __construct(
		public int $rank,
		public ?DateTimeImmutable $date,
	) {
	}//end __construct()
}//end class
