<?php

/**
 * One attempt to run a lifecycle verb on a task, as authorization sees it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task\Authorization;

use OCA\OpenRegister\Kernel\Contracts\TaskInterface;

/**
 * The verb, the task and the (already determined, non-empty) acting identity.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskVerbAttempt {

	/**
	 * Constructor.
	 *
	 * @param string        $verb The verb attempted (also used in denial messages).
	 * @param TaskInterface $task The task acted on.
	 * @param string        $uid  The acting identity.
	 */
	public function __construct(
		public readonly string $verb,
		public readonly TaskInterface $task,
		public readonly string $uid,
	) {

	}//end __construct()
}//end class
