<?php

/**
 * AuditTrailPageQueryInterface: the instance-wide audit page reads callers outside the component use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The audit trail page and export reads, newest first, and the largest page one call answers.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Bound to AuditTrailPageQuery.
 */
interface AuditTrailPageQueryInterface {

	/**
	 * The largest page one call answers.
	 *
	 * @var int
	 */
	public const MAX_LIMIT = 500;

	/**
	 * One page, newest first.
	 *
	 * @param AuditTrail\AuditPageRequest $request The criteria (keys of AuditTrailPageQuery::FILTER_COLUMNS plus `from`
	 *                                             and `to`, and the full-text search), the page size (at most
	 *                                             MAX_LIMIT) and the cursor.
	 *
	 * @return array{results: AuditTrail[], nextCursor: int|null} The rows and the cursor of the next page, null at the end.
	 *
	 * @throws \InvalidArgumentException When `from` or `to` is not a date.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-an-instance-wide-audit-list-with-filters
	 */
	public function page(AuditTrail\AuditPageRequest $request): array;

	/**
	 * Every row matching the filters, in pages, up to a ceiling.
	 *
	 * @param AuditTrail\AuditTrailCriteria $criteria The filters, as for page(), and the full-text search.
	 * @param int                           $ceiling  The most rows to return.
	 *
	 * @return array{results: AuditTrail[], truncated: bool} The rows, and whether more matched than the ceiling.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
	 */
	public function collect(AuditTrail\AuditTrailCriteria $criteria, int $ceiling): array;
}//end interface
