<?php

/**
 * SearchTrailFilterRun: one column filter being added to a search trail query.
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

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The query and the column one filter is being applied to.
 *
 * @spec openspec/specs/search-trail-recording/spec.md
 */
final class SearchTrailFilterRun {

	/**
	 * Constructor.
	 *
	 * @param IQueryBuilder $qb    The query under construction.
	 * @param string        $field The filtered column.
	 */
	public function __construct(
		public readonly IQueryBuilder $qb,
		public readonly string $field,
	) {
	}//end __construct()
}//end class
