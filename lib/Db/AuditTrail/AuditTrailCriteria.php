<?php

/**
 * AuditTrailCriteria: the filters and the full-text term of one audit list read.
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
 * Which audit rows a list read selects: the filter keys of AuditTrailPageQuery and a search term.
 *
 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-an-instance-wide-audit-list-with-filters
 */
final class AuditTrailCriteria {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $filters Keys of AuditTrailPageQuery::FILTER_COLUMNS plus `from` and `to`.
	 * @param string|null          $search  Full text on the change summary.
	 */
	public function __construct(
		public readonly array $filters,
		public readonly ?string $search=null,
	) {
	}//end __construct()
}//end class
