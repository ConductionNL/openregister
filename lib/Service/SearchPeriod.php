<?php

/**
 * OpenRegister SearchPeriod
 *
 * The optional start and end of the period a search trail report covers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

// No strict_types: same mode as SearchTrailStatistics, which builds and reads it.

namespace OCA\OpenRegister\Service;

use DateTime;

/**
 * A report period; either end may be open.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchPeriod {
	/**
	 * Constructor.
	 *
	 * @param DateTime|null $from Start date of the period
	 * @param DateTime|null $to   End date of the period
	 */
	public function __construct(
		public readonly ?DateTime $from = null,
		public readonly ?DateTime $to = null,
	) {
	}//end __construct()

	/**
	 * The from/to of the period as strings.
	 *
	 * @return array{from: string|null, to: string|null}
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function bounds(): array {
		return [
			'from' => $this->from?->format('Y-m-d H:i:s'),
			'to' => $this->to?->format('Y-m-d H:i:s'),
		];
	}//end bounds()

	/**
	 * Number of calendar days the period spans, both ends included; null without both ends.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function days(): ?int {
		if ($this->from === null || $this->to === null) {
			return null;
		}

		return $this->from->diff($this->to)->days + 1;
	}//end days()
}//end class
