<?php

/**
 * DateHistogramUnion
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * One date histogram facet counted with UNION ALL over several magic tables.
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */
final class DateHistogramUnion {

	/**
	 * Constructor.
	 *
	 * @param TermsFacetUnion $union    The tables, date column and search filters of the facet
	 * @param string          $interval The histogram interval (day, week, month, quarter, year)
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function __construct(
		public readonly TermsFacetUnion $union,
		public readonly string $interval,
	) {
	}//end __construct()
}//end class
