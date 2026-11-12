<?php

/**
 * The instance-wide audit page: its keyset list and its export.
 *
 * The list pages by cursor through AuditTrailPageQueryInterface and never counts the
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

use OCA\OpenRegister\BackgroundJob\AuditExportSource;
use OCA\OpenRegister\BackgroundJob\JobHandles;
use OCA\OpenRegister\Db\AuditTrail\AuditPageRequest;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailCriteria;
use OCA\OpenRegister\Db\AuditTrailPageQueryInterface;
use OCP\BackgroundJob\IJobList;

/**
 * The audit page's list and export.
 *
 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-an-instance-wide-audit-list-with-filters
 */
class AuditTrailPageService implements AuditTrailPageServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param AuditExportSource $source  The keyset query, and the renderer of an export.
	 * @param IJobList          $jobList Queues a large export.
	 */
	public function __construct(
		private readonly AuditExportSource $source,
		private readonly IJobList $jobList,
	) {
	}//end __construct()

	/**
	 * One page, newest first.
	 *
	 * @param AuditTrailQuery $query  The filters (see AuditTrailPageQueryInterface::page()) and search.
	 * @param int             $limit  Page size.
	 * @param int             $cursor The cursor, 0 for the first page.
	 *
	 * @return array{results: array, limit: int, cursor: int, nextCursor: int|null} The page.
	 *
	 * @throws \InvalidArgumentException When a period bound is not a date.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-an-instance-wide-audit-list-with-filters
	 */
	public function page(AuditTrailQuery $query, int $limit, int $cursor): array {
		$page = $this->source->pageQuery->page(
			request: new AuditPageRequest(criteria: new AuditTrailCriteria(filters: $query->filters, search: $query->search), limit: $limit, beforeId: $cursor)
		);

		return [
			'results' => $page['results'],
			'limit' => max(1, min($limit, AuditTrailPageQueryInterface::MAX_LIMIT)),
			'cursor' => $cursor,
			'nextCursor' => $page['nextCursor'],
		];
	}//end page()

	/**
	 * Export the filtered list, or queue it when it is too large.
	 *
	 * @param AuditTrailQuery $query          The filters and the full-text search on the change summary.
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
	public function export(AuditTrailQuery $query, string $format, bool $includeChanges, ?string $actor): array {
		return $this->exportRows(export: new AuditTrailExport(query: $query, format: $format, includeChanges: $includeChanges, actor: $actor));
	}//end export()

	/**
	 * Export inline under the ceiling, or queue the export for the requester above it.
	 *
	 * @param AuditTrailExport $export The query, format, change-summary flag and requester.
	 *
	 * @return array{queued: bool, rows: int, file: array{content: string, filename: string, contentType: string}|null} The outcome.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
	 */
	private function exportRows(AuditTrailExport $export): array {
		$query = $export->query;
		$collected = $this->source->pageQuery->collect(
			criteria: new AuditTrailCriteria(filters: $query->filters, search: $query->search),
			ceiling: JobHandles::AUDIT_TRAIL_EXPORT_INLINE_LIMIT
		);

		if ($collected['truncated'] === true) {
			$this->jobList->add(
				JobHandles::AUDIT_TRAIL_EXPORT,
				[
					'actor' => $export->actor,
					'filters' => $query->filters,
					'search' => $query->search,
					'format' => $export->format,
					'includeChanges' => $export->includeChanges,
				]
			);

			return ['queued' => true, 'rows' => count($collected['results']), 'file' => null];
		}

		$file = $this->source->logService->exportRows(
			format: $export->format,
			logs: $collected['results'],
			config: ['includeChanges' => $export->includeChanges]
		);

		return ['queued' => false, 'rows' => count($collected['results']), 'file' => $file];
	}//end exportRows()

	/**
	 * The query a page or an export is selected under.
	 *
	 * @param array<string, mixed> $filters The filters (see AuditTrailPageQueryInterface::page()).
	 * @param string|null          $search  Full text on the change summary.
	 *
	 * @return AuditTrailQuery The query.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-an-instance-wide-audit-list-with-filters
	 */
	public function query(array $filters, ?string $search): AuditTrailQuery {
		return new AuditTrailQuery(filters: $filters, search: $search);
	}//end query()
}//end class
