<?php

/**
 * IntegrationsJobHandlesInterface: the class names of the Integrations component's queued jobs.
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
 * The class names of the Integrations jobs that Integrations listeners enqueue.
 *
 * A caller outside lib/BackgroundJob only needs the job's class string to hand
 * to the job list, never the job class itself. Each constant is exactly the
 * value the caller used before (`Job::class` or `Job::CONSTANT`), so what
 * lands on the job list does not change. It holds only Integrations jobs, so no lower
 * area lists Integrations work; it is a constant holder, never bound or resolved.
 */
interface IntegrationsJobHandlesInterface {

	/**
	 * Context Chat submission job.
	 */
	public const CONTEXT_CHAT_SUBMISSION = ContextChatSubmissionJob::class;
}//end interface
