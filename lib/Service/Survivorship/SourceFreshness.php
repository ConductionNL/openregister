<?php

/**
 * OpenRegister SourceFreshness
 *
 * How fresh one source's value is: its decay window, its anchor date and the
 * instant it is judged at.
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
 * The freshness of one source's value: decay window, anchor and reference instant.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
 */
final readonly class SourceFreshness {

	/**
	 * Bundle the freshness facts.
	 *
	 * @param int|null               $decayDays Decay window in days, or null for no decay.
	 * @param DateTimeImmutable|null $anchor    The source's freshness anchor date, or null.
	 * @param DateTimeImmutable      $asOf      Reference instant.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
	 */
	public function __construct(
		public ?int $decayDays,
		public ?DateTimeImmutable $anchor,
		public DateTimeImmutable $asOf,
	) {
	}//end __construct()
}//end class
