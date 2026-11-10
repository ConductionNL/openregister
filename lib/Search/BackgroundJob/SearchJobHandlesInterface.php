<?php

/**
 * SearchJobHandlesInterface: the class names of the Search component's background jobs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Search\BackgroundJob
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

namespace OCA\OpenRegister\Search\BackgroundJob;

/**
 * The class names of the Search jobs that other components start.
 *
 * A caller outside lib/Search only needs the job's class string to hand to the job list, never the job class
 * itself, so the job classes stay internal to this component. Each constant is exactly the value the caller used
 * before (`Job::class`), so what lands on the job list does not change. An interface, so a caller outside
 * lib/Search names no concrete Search class; it is a constant holder, never bound or resolved.
 *
 * @spec openspec/specs/operations-console/spec.md
 */
interface SearchJobHandlesInterface {

	/**
	 * The search index rebuild, started from the operations console.
	 */
	public const SEARCH_INDEX_REBUILD = SearchIndexRebuildJob::class;
}//end interface
