<?php

/**
 * JobHandles: the queued jobs other components put on the job list, by name.
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
 * The class names (and the argument constants) of the background jobs that
 * controllers, listeners and services enqueue.
 *
 * A caller outside lib/BackgroundJob only needs the job's class string to hand
 * to IJobList::add() / has(), never the job class itself. Naming the job here
 * keeps the job classes internal to this component: each constant is exactly
 * the value the caller used before (`Job::class` or `Job::CONSTANT`), so what
 * lands on the job list does not change.
 */
final class JobHandles {

	/**
	 * Deferred x-openregister-notifications dispatch job.
	 */
	public const ANNOTATION_NOTIFICATION_DISPATCH = AnnotationNotificationDispatchJob::class;

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
	 * Queued automatic lifecycle transition job.
	 */
	public const AUTO_TRANSITION = AutoTransitionJob::class;

	/**
	 * Bulk job runner.
	 */
	public const BULK_JOB_RUNNER = BulkJobRunner::class;

	/**
	 * Bulk legal hold job.
	 */
	public const BULK_LEGAL_HOLD = BulkLegalHoldJob::class;

	/**
	 * Cache clear-and-warm maintenance job.
	 */
	public const CACHE_CLEAR_AND_WARM = CacheClearAndWarmJob::class;

	/**
	 * Consistency check maintenance job.
	 */
	public const CONSISTENCY_CHECK = ConsistencyCheckJob::class;

	/**
	 * Deferred object event job.
	 */
	public const DEFERRED_OBJECT_EVENT = DeferredObjectEventJob::class;

	/**
	 * File text extraction job.
	 */
	public const FILE_TEXT_EXTRACTION = FileTextExtractionJob::class;

	/**
	 * Import preview runner.
	 */
	public const IMPORT_PREVIEW_RUNNER = ImportPreviewRunner::class;

	/**
	 * The import preview runner's preview phase.
	 */
	public const IMPORT_PREVIEW_PHASE_PREVIEW = ImportPreviewRunner::PHASE_PREVIEW;

	/**
	 * The import preview runner's commit phase.
	 */
	public const IMPORT_PREVIEW_PHASE_COMMIT = ImportPreviewRunner::PHASE_COMMIT;

	/**
	 * Object cleanup job.
	 */
	public const OBJECT_CLEANUP = ObjectCleanupJob::class;

	/**
	 * Object text extraction job.
	 */
	public const OBJECT_TEXT_EXTRACTION = ObjectTextExtractionJob::class;

	/**
	 * Related retention recalculation job.
	 */
	public const RELATED_RETENTION_RECALCULATION = RelatedRetentionRecalculationJob::class;

	/**
	 * Schema run job.
	 */
	public const SCHEMA_RUN = SchemaRunJob::class;

	/**
	 * Source record recompute job.
	 */
	public const SOURCE_RECORD_RECOMPUTE = SourceRecordRecomputeJob::class;

	/**
	 * Subject export job.
	 */
	public const SUBJECT_EXPORT = SubjectExportJob::class;

	/**
	 * Transfer execution job.
	 */
	public const TRANSFER_EXECUTION = TransferExecutionJob::class;

	/**
	 * Translation projection job.
	 */
	public const TRANSLATION_PROJECTION = TranslationProjectionJob::class;
}//end class
