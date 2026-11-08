<?php

/**
 * AuditTrailPageServiceInterface: what callers outside the component use of AuditTrailPageService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
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

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AuditTrailPageQuery;

/**
 * The audit-trail page and export the audit trail endpoints serve.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AuditTrailPageServiceInterface {

	/**
	 * Export the filtered list, or queue it when it is too large.
	 *
	 * @param array<string, mixed> $filters        The filters.
	 * @param string|null          $search         Full text on the change summary.
	 * @param string               $format         csv, json, xml or txt.
	 * @param bool                 $includeChanges Whether to include the change summary.
	 * @param string|null          $actor          The requester, who receives a queued file.
	 *
	 * @return array{queued: bool, rows: int, file: array{content: string, filename: string, contentType: string}|null} The outcome.
	 *
	 * @throws \InvalidArgumentException When a period bound or the format is not valid.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
	 */
	public function export(array $filters, ?string $search, string $format, bool $includeChanges, ?string $actor): array;

	/**
	 * One page, newest first.
	 *
	 * @param array<string, mixed> $filters The filters (see AuditTrailPageQuery::page()).
	 * @param string|null          $search  Full text on the change summary.
	 * @param int                  $limit   Page size.
	 * @param int                  $cursor  The cursor, 0 for the first page.
	 *
	 * @return array{results: array, limit: int, cursor: int, nextCursor: int|null} The page.
	 *
	 * @throws \InvalidArgumentException When a period bound is not a date.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-an-instance-wide-audit-list-with-filters
	 */
	public function page(array $filters, ?string $search, int $limit, int $cursor): array;
}//end interface
