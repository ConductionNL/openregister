<?php

/**
 * TaskAdapterOptions: the inbox row and extra fields a task is presented with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
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
 * What a task is presented with besides itself: its inbox row and the extra fields rules read.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */
final class TaskAdapterOptions {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $row   The task's inbox row.
	 * @param array<string, mixed> $extra Extra fields the rules read.
	 */
	public function __construct(
		public readonly array $row = [],
		public readonly array $extra = [],
	) {
	}//end __construct()
}//end class
