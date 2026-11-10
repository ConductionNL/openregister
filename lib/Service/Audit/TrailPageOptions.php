<?php

/**
 * OpenRegister TrailPageOptions
 *
 * Which page of the readable audit trail is asked: its size, the cursor it
 * resumes from and the query it is walked under.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

/**
 * The optional settings of one readable audit trail page.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
 */
final class TrailPageOptions {

	/**
	 * Constructor.
	 *
	 * @param int             $limit  How many readable rows the caller asked for.
	 * @param int|string|null $cursor A sealed cursor the list handed out under the same caller, filters and search.
	 * @param AuditTrailQuery $query  The filters and search, from ReadableAuditTrailLister::query().
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
	 */
	public function __construct(
		public readonly int $limit = 20,
		public readonly int|string|null $cursor = null,
		public readonly AuditTrailQuery $query = new AuditTrailQuery(),
	) {
	}//end __construct()
}//end class
