<?php

/**
 * ConfigurationJobHandlesInterface: the class names of the Configuration component's queued jobs.
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
 * The class names (and the argument constants) of the Configuration jobs that
 * the operations console and the import preview enqueue.
 *
 * A caller outside lib/BackgroundJob only needs the job's class string to hand
 * to the job list, never the job class itself. Each constant is exactly the
 * value the caller used before (`Job::class` or `Job::CONSTANT`), so what
 * lands on the job list does not change. It holds only Configuration jobs, so no lower
 * area lists Configuration work; it is a constant holder, never bound or resolved.
 */
interface ConfigurationJobHandlesInterface {

	/**
	 * Cache clear-and-warm maintenance job.
	 */
	public const CACHE_CLEAR_AND_WARM = CacheClearAndWarmJob::class;

	/**
	 * Consistency check maintenance job.
	 */
	public const CONSISTENCY_CHECK = ConsistencyCheckJob::class;

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
}//end interface
