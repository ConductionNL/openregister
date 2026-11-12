<?php

/**
 * StateChangePeriod: the inclusive period a state-history change search covers.
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
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTimeInterface;

/**
 * Start and end of a period, both inclusive.
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
final class StateChangePeriod {

	/**
	 * Constructor.
	 *
	 * @param DateTimeInterface $after  Start of the period, inclusive.
	 * @param DateTimeInterface $before End of the period, inclusive.
	 */
	public function __construct(
		public readonly DateTimeInterface $after,
		public readonly DateTimeInterface $before,
	) {
	}//end __construct()
}//end class
