<?php

/**
 * A flow run was persisted in a terminal status.
 *
 * Dispatched from the ONE choke point every terminal write passes —
 * {@see \OCA\OpenRegister\Db\FlowRunMapper::update()} — rather than from
 * each of the several service sites that set a terminal status, so no new
 * failure path can forget it. It may fire MORE THAN ONCE for one run (the
 * stale-run reaper can observe terminality again, and later updates of a
 * terminal run re-trigger it); every listener must therefore be idempotent,
 * which the task cancellation propagation is by construction (design D-8).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-that-has-become-moot-is-terminated-not-orphaned
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Carries the terminal run's identity and status.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-that-has-become-moot-is-terminated-not-orphaned
 */
class FlowRunTerminalEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string $runUuid The run's public uuid.
	 * @param string $status The terminal status it was persisted with.
	 */
	public function __construct(
		private readonly string $runUuid,
		private readonly string $status,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The run's public uuid.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-that-has-become-moot-is-terminated-not-orphaned
	 */
	public function getRunUuid(): string {
		return $this->runUuid;
	}//end getRunUuid()

	/**
	 * The terminal status.
	 *
	 * @return string One of FlowRun::TERMINAL.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-that-has-become-moot-is-terminated-not-orphaned
	 */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()
}//end class
