<?php

/**
 * What a task's notification payload is built from besides the task row itself.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The optional inputs of a task payload: the inbox row and the event-scoped fields.
 *
 * @psalm-immutable
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */
final class TaskPayloadOptions {
	/**
	 * Hold the options.
	 *
	 * @param array<string, mixed> $row   The task's inbox row: display title, subject and the
	 *                                    DERIVED temporal fields, so no derivation happens here.
	 * @param array<string, mixed> $extra Event-scoped payload fields a rule may address
	 *                                    (previousAssignee, the write-back actor and reason).
	 */
	public function __construct(
		public readonly array $row = [],
		public readonly array $extra = [],
	) {
	}//end __construct()
}//end class
