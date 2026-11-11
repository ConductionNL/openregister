<?php

/**
 * SearchTrailCriteria: which search trails a listing or a count is about.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;

/**
 * The column filters, the search term and the created window of a trail query.
 *
 * One page of trails and the count of all of them are asked with the same
 * criteria, so the two cannot disagree about what "matching" means.
 */
final class SearchTrailCriteria {

	/**
	 * Constructor.
	 *
	 * @param array         $filters Column filters, as SearchTrailMapper applies them.
	 * @param string|null   $search  Search term, or null for none.
	 * @param DateTime|null $from    Created on or after, or null for no lower bound.
	 * @param DateTime|null $to      Created on or before, or null for no upper bound.
	 */
	public function __construct(
		public readonly array $filters = [],
		public readonly ?string $search = null,
		public readonly ?DateTime $from = null,
		public readonly ?DateTime $to = null,
	) {
	}//end __construct()
}//end class
