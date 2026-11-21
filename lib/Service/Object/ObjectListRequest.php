<?php

/**
 * OpenRegister ObjectListRequest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * One object listing: the page and filters, the register and schema it reads, the object
 * selection and whether files are hydrated onto the rows.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ObjectListRequest {

	/**
	 * Constructor.
	 *
	 * @param ListQuery       $query     Limit, offset, filters, sort and search; unbounded lists every row.
	 * @param ReadScope       $scope     The register and schema the listing reads, either may be absent.
	 * @param ObjectSelection $selection The ids and used objects the listing is restricted to.
	 * @param bool            $files     Whether file information is hydrated onto the rows.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ListQuery $query = new ListQuery(limit: ListQuery::UNBOUNDED),
		public readonly ReadScope $scope = new ReadScope(),
		public readonly ObjectSelection $selection = new ObjectSelection(),
		public readonly bool $files = false,
	) {
	}//end __construct()
}//end class
