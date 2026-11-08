<?php

/**
 * The stores and diagnostics the calendar projector writes through.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Db\TaskProjectionStateMapper;
use Psr\Log\LoggerInterface;

/**
 * The dependency bundle of {@see TaskCalendarProjector}.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
 */
final class TaskCalendarProjectorServices {
	/**
	 * Bundle the services.
	 *
	 * @param CalDavBackend $calDavBackend The calendar store.
	 * @param VtodoCalendarLocator $calendars Calendar selection by uid.
	 * @param TaskProjectionStateMapper $states The per-task projection state.
	 * @param LoggerInterface $logger Names skipped and failed tasks.
	 */
	public function __construct(
		public readonly CalDavBackend $calDavBackend,
		public readonly VtodoCalendarLocator $calendars,
		public readonly TaskProjectionStateMapper $states,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
