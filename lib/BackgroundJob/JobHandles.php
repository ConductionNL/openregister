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
 * It names only Core jobs. The jobs of a higher area are named on that area's
 * own holder in this directory (ComplianceJobHandlesInterface,
 * ConfigurationJobHandlesInterface, IntegrationsJobHandlesInterface and
 * ProcessesJobHandlesInterface), so this Core class lists no job of an area
 * above Core.
 */
final class JobHandles {

	/**
	 * Deferred object event job.
	 */
	public const DEFERRED_OBJECT_EVENT = DeferredObjectEventJob::class;

	/**
	 * Object cleanup job.
	 */
	public const OBJECT_CLEANUP = ObjectCleanupJob::class;

	/**
	 * Schema run job.
	 */
	public const SCHEMA_RUN = SchemaRunJob::class;

	/**
	 * Reindex of an administrator's selection of objects.
	 */
	public const REINDEX_SELECTION = ReindexSelectionJob::class;
}//end class
