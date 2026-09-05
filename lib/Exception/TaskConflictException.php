<?php

/**
 * A task lifecycle verb lost a race or hit a terminal task.
 *
 * Two cases share this shape because both are "the task is not in the state
 * your verb assumed, and retrying blindly is wrong": a claim that lost the
 * conditional update to a concurrent claimer, and any verb applied to a task
 * already in a terminal state. The message names the current state so the
 * caller learns what actually holds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * A task state conflict: a lost claim race or a verb against a terminal task.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
class TaskConflictException extends RuntimeException {
}//end class
