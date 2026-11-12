<?php

/**
 * OpenRegister Destruction Execution Job contract
 *
 * What a caller outside the background-job component needs to queue the
 * execution of an approved destruction list: the job's class name and the
 * argument it reads, without naming the job class itself.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

/**
 * How an approved destruction list is queued for execution.
 *
 * `IJobList::add(DestructionExecutionJobInterface::JOB_CLASS, [ARGUMENT_LIST_UUID => $uuid])`
 * queues exactly what `IJobList::add(DestructionExecutionJob::class, ...)` did.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
interface DestructionExecutionJobInterface {
	/**
	 * The job class the job list is asked to run.
	 *
	 * @var string
	 */
	public const JOB_CLASS = DestructionExecutionJob::class;

	/**
	 * The argument key holding the uuid of the list to execute.
	 *
	 * @var string
	 */
	public const ARGUMENT_LIST_UUID = 'destructionListUuid';
}//end interface
