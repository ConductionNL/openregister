<?php

/**
 * TaskActorRef: the task a lifecycle verb acts on, and who acts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * One task, by uuid, and the identity acting on it; null when the system acts.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskActorRef {

	/**
	 * Constructor
	 *
	 * @param string $uuid The task uuid.
	 * @param string|null $actor The acting user id, or null for the system.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ?string $actor,
	) {
	}//end __construct()
}//end class
