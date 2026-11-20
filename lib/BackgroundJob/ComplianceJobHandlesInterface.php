<?php

/**
 * ComplianceJobHandlesInterface: the class names of the Compliance component's queued jobs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

/**
 * The class names (and the argument constants) of the Compliance jobs that
 * Compliance controllers, listeners and services enqueue.
 *
 * A caller outside lib/BackgroundJob only needs the job's class string to hand
 * to IJobList::add() / has(), never the job class itself. Each constant is
 * exactly the value the caller used before (`Job::class` or `Job::CONSTANT`),
 * so what lands on the job list does not change. It holds only Compliance
 * jobs, so a Compliance caller names no class of a lower area that lists
 * Compliance work; it is a constant holder, never bound or resolved.
 */
interface ComplianceJobHandlesInterface {

	/**
	 * Audit trail export job.
	 */
	public const AUDIT_TRAIL_EXPORT = AuditTrailExportJob::class;

	/**
	 * Rows the audit page exports inline before it queues the export job.
	 */
	public const AUDIT_TRAIL_EXPORT_INLINE_LIMIT = AuditTrailExportJob::INLINE_LIMIT;

	/**
	 * The user folder the audit trail export job writes into.
	 */
	public const AUDIT_TRAIL_EXPORT_FOLDER = AuditTrailExportJob::FOLDER;

	/**
	 * Bulk legal hold job.
	 */
	public const BULK_LEGAL_HOLD = BulkLegalHoldJob::class;

	/**
	 * Related retention recalculation job.
	 */
	public const RELATED_RETENTION_RECALCULATION = RelatedRetentionRecalculationJob::class;

	/**
	 * Subject export job.
	 */
	public const SUBJECT_EXPORT = SubjectExportJob::class;

	/**
	 * e-Depot transfer execution job (one attempt, re-enqueued with backoff).
	 */
	public const TRANSFER_EXECUTION = TransferExecutionJob::class;
}//end interface
