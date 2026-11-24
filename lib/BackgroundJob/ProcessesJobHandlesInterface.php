<?php

/**
 * ProcessesJobHandlesInterface: the class names of the Processes component's queued jobs.
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
 * The class names of the Processes jobs that listeners and services enqueue.
 *
 * A caller outside lib/BackgroundJob only needs the job's class string to hand
 * to IJobList::add() or a deferral, never the job class itself. Each constant
 * is exactly the value the caller used before (`Job::class`), so what lands on
 * the job list does not change. It holds only Processes jobs, so no lower area
 * lists Processes work; it is a constant holder, never bound or resolved.
 */
interface ProcessesJobHandlesInterface {

	/**
	 * Deferred x-openregister-notifications dispatch job.
	 */
	public const ANNOTATION_NOTIFICATION_DISPATCH = AnnotationNotificationDispatchJob::class;

	/**
	 * Queued automatic lifecycle transition job.
	 */
	public const AUTO_TRANSITION = AutoTransitionJob::class;

	/**
	 * Bulk job runner.
	 */
	public const BULK_JOB_RUNNER = BulkJobRunner::class;

	/**
	 * File text extraction job.
	 */
	public const FILE_TEXT_EXTRACTION = FileTextExtractionJob::class;

	/**
	 * Object text extraction job.
	 */
	public const OBJECT_TEXT_EXTRACTION = ObjectTextExtractionJob::class;

	/**
	 * Source record recompute job.
	 */
	public const SOURCE_RECORD_RECOMPUTE = SourceRecordRecomputeJob::class;

	/**
	 * Translation projection job (TranslationProjectionListener defers it).
	 */
	public const TRANSLATION_PROJECTION = TranslationProjectionJob::class;
}//end interface
