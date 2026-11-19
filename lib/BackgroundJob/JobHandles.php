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
 *
 * The jobs of a higher area are named on that area's own holder in this
 * directory (ComplianceJobHandlesInterface and its siblings), so this Core
 * class lists no job of an area above Core.
 */
final class JobHandles {

	/**
	 * Cache clear-and-warm maintenance job.
	 */
	public const CACHE_CLEAR_AND_WARM = CacheClearAndWarmJob::class;

	/**
	 * Consistency check maintenance job.
	 */
	public const CONSISTENCY_CHECK = ConsistencyCheckJob::class;

	/**
	 * Context Chat submission job.
	 */
	public const CONTEXT_CHAT_SUBMISSION = ContextChatSubmissionJob::class;

	/**
	 * Deferred object event job.
	 */
	public const DEFERRED_OBJECT_EVENT = DeferredObjectEventJob::class;

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
	 * Schema run job.
	 */
	public const SCHEMA_RUN = SchemaRunJob::class;

	/**
	 * Transfer execution job.
	 */
	public const TRANSFER_EXECUTION = TransferExecutionJob::class;
}//end class
