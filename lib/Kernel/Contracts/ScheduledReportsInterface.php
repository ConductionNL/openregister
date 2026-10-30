<?php

/**
 * ScheduledReportsInterface: manage and run scheduled reports.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTimeInterface;
use OCA\OpenRegister\Db\ScheduledReport;

/**
 * Creates, reads, updates, deletes and runs scheduled reports, and guards them by owner. Implemented by ScheduledReportService.
 */
interface ScheduledReportsInterface {

	/**
	 * Assert the caller owns the report or is an admin.
	 *
	 * @param ScheduledReport $report The report.
	 * @param string $callerUid The calling user's uid.
	 * @param bool $callerIsAdmin Whether the caller is an admin.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the assertion fails.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function assertOwnerOrAdmin(ScheduledReport $report, string $callerUid, bool $callerIsAdmin): void;

	/**
	 * Create a scheduled report owned by the given user.
	 *
	 * @param array<string,mixed> $data Request payload.
	 * @param string $ownerUid The owning uid (always the caller — self-service creation).
	 *
	 * @return ScheduledReport
	 *
	 * @throws \InvalidArgumentException When validation fails.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function create(array $data, string $ownerUid): ScheduledReport;

	/**
	 * Delete a scheduled report. Only the owner or an admin may delete.
	 *
	 * @param int $id The scheduled report id.
	 * @param string $callerUid The calling user's uid.
	 * @param bool $callerIsAdmin Whether the caller is a Nextcloud administrator.
	 *
	 * @return void
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no row matches.
	 * @throws \RuntimeException When the caller does not own the row and is not an admin.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function delete(int $id, string $callerUid, bool $callerIsAdmin): void;

	/**
	 * Find a scheduled report by id.
	 *
	 * @param int $id The scheduled report id.
	 *
	 * @return ScheduledReport
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no row matches.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function find(int $id): ScheduledReport;

	/**
	 * List every scheduled report (admin listing).
	 *
	 * @return ScheduledReport[]
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function findAllForAdmin(): array;

	/**
	 * List the reports owned by a user.
	 *
	 * @param string $ownerUid The owning uid.
	 *
	 * @return ScheduledReport[]
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function findForOwner(string $ownerUid): array;

	/**
	 * Whether a report is due to run now.
	 *
	 * Catch-up-safe elapsed-period check per design.md: due when never run,
	 * or when at least a full schedule period has elapsed since the last run.
	 * Disabled reports are never due.
	 *
	 * @param ScheduledReport $report The report to check.
	 * @param DateTimeInterface $now The reference time.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function isDue(ScheduledReport $report, DateTimeInterface $now): bool;

	/**
	 * Execute a single scheduled report: export as the owner, deliver per
	 * `deliveryMode` (Files, email, or both), notify the owner, and persist
	 * the outcome. Never throws — all failures (including
	 * `ExportTooLargeException`) are caught, recorded on the report, and
	 * notified, so a caller (job or run-now) can iterate a batch without
	 * per-report try/catch of its own (though callers SHOULD still wrap
	 * this call for defence in depth — see ScheduledReportJob).
	 *
	 * The email leg is isolated from the Files leg: when `deliveryMode` is
	 * `both` and Files delivery succeeds but email fails, the run is marked
	 * `email_failed` (not `failed`) — the export WAS delivered, just not by
	 * every requested channel. When `deliveryMode` is `email` only and the
	 * email leg fails outright, the run is marked `failed` since nothing was
	 * delivered.
	 *
	 * @param ScheduledReport $report The report to run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function runOne(ScheduledReport $report): void;

	/**
	 * Update an existing scheduled report. Only the owner or an admin may update.
	 *
	 * @param int $id The scheduled report id.
	 * @param array<string,mixed> $data Fields to update (same shape as create()).
	 * @param string $callerUid The calling user's uid.
	 * @param bool $callerIsAdmin Whether the caller is a Nextcloud administrator.
	 *
	 * @return ScheduledReport
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no row matches.
	 * @throws \RuntimeException When the caller does not own the row and is not an admin.
	 * @throws \InvalidArgumentException When validation fails.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function update(int $id, array $data, string $callerUid, bool $callerIsAdmin): ScheduledReport;
}//end interface
