<?php

/**
 * The instance-wide audit page: its keyset list and its export.
 *
 * The list pages by cursor through AuditTrailPageQuery and never counts the
 * table. The export selects its rows through the same query and the same
 * filters, so the file cannot disagree with the list; up to
 * AuditTrailExportJob::INLINE_LIMIT rows it is rendered in the request, past
 * it the export is queued and the requester is notified when the file is in
 * their Files.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-an-instance-wide-audit-list-with-filters
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\BackgroundJob\AuditTrailExportJob;
use OCA\OpenRegister\Db\AuditTrailPageQuery;
use OCA\OpenRegister\Service\LogService;
use OCP\BackgroundJob\IJobList;

/**
 * The audit page's list and export.
 *
 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-an-instance-wide-audit-list-with-filters
 */
class AuditTrailPageService {

	/**
	 * Constructor.
	 *
	 * @param AuditTrailPageQuery $pageQuery  The keyset query.
	 * @param LogService          $logService Renders an export.
	 * @param IJobList            $jobList    Queues a large export.
	 */
	public function __construct(
		private readonly AuditTrailPageQuery $pageQuery,
		private readonly LogService $logService,
		private readonly IJobList $jobList,
	) {
	}//end __construct()

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
	public function page(array $filters, ?string $search, int $limit, int $cursor): array {
		$page = $this->pageQuery->page(filters: $filters, search: $search, limit: $limit, beforeId: $cursor);

		return [
			'results' => $page['results'],
			'limit' => max(1, min($limit, AuditTrailPageQuery::MAX_LIMIT)),
			'cursor' => $cursor,
			'nextCursor' => $page['nextCursor'],
		];
	}//end page()

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
	public function export(array $filters, ?string $search, string $format, bool $includeChanges, ?string $actor): array {
		$collected = $this->pageQuery->collect(filters: $filters, search: $search, ceiling: AuditTrailExportJob::INLINE_LIMIT);

		if ($collected['truncated'] === true) {
			$this->jobList->add(
				AuditTrailExportJob::class,
				[
					'actor' => $actor,
					'filters' => $filters,
					'search' => $search,
					'format' => $format,
					'includeChanges' => $includeChanges,
				]
			);

			return ['queued' => true, 'rows' => count($collected['results']), 'file' => null];
		}

		$file = $this->logService->exportRows(
			format: $format,
			logs: $collected['results'],
			config: ['includeChanges' => $includeChanges]
		);

		return ['queued' => false, 'rows' => count($collected['results']), 'file' => $file];
	}//end export()
}//end class
