<?php

/**
 * AuditPageRequest: one cursor page of the instance-wide audit list.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\AuditTrail
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\AuditTrail;

/**
 * Which rows one page selects, how many, and the cursor it starts below.
 *
 * The operation object of AuditTrailPageQuery::page().
 */
final class AuditPageRequest {

	/**
	 * Constructor.
	 *
	 * @param AuditTrailCriteria $criteria The filters and the search term.
	 * @param int                $limit    Page size; the reader clamps it to its maximum.
	 * @param int|null           $beforeId The cursor: only rows with a smaller id.
	 */
	public function __construct(
		public readonly AuditTrailCriteria $criteria,
		public readonly int $limit,
		public readonly ?int $beforeId = null,
	) {
	}//end __construct()
}//end class
