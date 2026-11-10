<?php

/**
 * CreatedRange: the created window a search trail statistic reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SearchTrail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SearchTrail;

use DateTime;

/**
 * The window of `created` moments a statistic reads; a null bound is open.
 *
 * @spec openspec/specs/search-trail-recording/spec.md
 */
final class CreatedRange {

	/**
	 * Constructor.
	 *
	 * @param DateTime|null $from Start of the range, or open.
	 * @param DateTime|null $to   End of the range, or open.
	 */
	public function __construct(
		public readonly ?DateTime $from = null,
		public readonly ?DateTime $to = null,
	) {
	}//end __construct()
}//end class
