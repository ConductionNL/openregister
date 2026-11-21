<?php

/**
 * OpenRegister TransitionNotice
 *
 * What one task transition tells the notification rules.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
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

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The fields a transition adds to the task's notification payload, and the
 * context the task rules match on.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */
final class TransitionNotice {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $extra   Fields added to the task's notification payload.
	 * @param array<string, mixed> $context The transition context the rules match on.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public function __construct(
		public readonly array $extra,
		public readonly array $context,
	) {
	}//end __construct()
}//end class
