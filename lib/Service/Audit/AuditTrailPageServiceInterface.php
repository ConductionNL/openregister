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

use OCA\OpenRegister\Db\AuditTrailPageQueryInterface;

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
	 * @param AuditTrailQuery $query          The filters and the full-text search on the change summary, from {@see query()}.
	 * @param string          $format         csv, json, xml or txt.
	 * @param bool            $includeChanges Whether to include the change summary.
	 * @param string|null     $actor          The requester, who receives a queued file.
	 *
	 * @return array{queued: bool, rows: int, file: array{content: string, filename: string, contentType: string}|null} The outcome.
	 *
	 * @throws \InvalidArgumentException When a period bound or the format is not valid.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
	 */
	public function export(AuditTrailQuery $query, string $format, bool $includeChanges, ?string $actor): array;

	/**
	 * One page, newest first.
	 *
	 * @param AuditTrailQuery $query  The filters (see AuditTrailPageQueryInterface::page()) and search, from {@see query()}.
	 * @param int             $limit  Page size.
	 * @param int             $cursor The cursor, 0 for the first page.
	 *
	 * @return array{results: array, limit: int, cursor: int, nextCursor: int|null} The page.
	 *
	 * @throws \InvalidArgumentException When a period bound is not a date.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-an-instance-wide-audit-list-with-filters
	 */
	public function page(AuditTrailQuery $query, int $limit, int $cursor): array;

	/**
	 * The query a page or an export is selected under: the filters and the full-text search.
	 *
	 * Built here, so callers outside this component never name the query class.
	 *
	 * @param array<string, mixed> $filters The filters (see AuditTrailPageQueryInterface::page()).
	 * @param string|null          $search  Full text on the change summary.
	 *
	 * @return AuditTrailQuery The query, to hand to {@see page()} or {@see export()}.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-an-instance-wide-audit-list-with-filters
	 */
	public function query(array $filters, ?string $search): AuditTrailQuery;
}//end interface
