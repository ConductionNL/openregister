<?php

/**
 * QueuedJobClassesInterface: the job classes callers outside BackgroundJob queue by name.
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
 * The class names of the queued jobs that a controller or listener adds to the
 * job list. Callers outside this component queue the constant instead of naming
 * the job class, so the job stays inside BackgroundJob. Each constant is the job's
 * own ::class string, so the job list stores exactly the same name as before.
 */
interface QueuedJobClassesInterface {

	/**
	 * Executes one transfer list (TransferController queues it on execute).
	 *
	 * @var class-string
	 */
	public const TRANSFER_EXECUTION = TransferExecutionJob::class;

	/**
	 * Projects an object's translatable properties (TranslationProjectionListener defers it).
	 *
	 * @var class-string
	 */
	public const TRANSLATION_PROJECTION = TranslationProjectionJob::class;
}//end interface
