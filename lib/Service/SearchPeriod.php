<?php

/**
 * OpenRegister SearchPeriod
 *
 * The optional date range a search-trail statistic covers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use DateTime;

/**
 * The date range of one search-trail statistic; either end may be open.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
 */
final class SearchPeriod {

	/**
	 * Constructor.
	 *
	 * @param DateTime|null $from Start of the range, or null for no lower bound.
	 * @param DateTime|null $to   End of the range, or null for no upper bound.
	 */
	public function __construct(
		public readonly ?DateTime $from = null,
		public readonly ?DateTime $to = null,
	) {
	}//end __construct()
}//end class
