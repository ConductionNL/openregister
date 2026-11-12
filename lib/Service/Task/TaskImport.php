<?php

/**
 * One task carried into the engine through the trusted import path.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use Closure;
use DateTime;

/**
 * The task fields, the creating identity and, when the caller has them, the
 * rule for which blockers it may name and the moment the task was created,
 * as {@see TaskService::importTask()} takes them.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
 */
final class TaskImport {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data           The task fields, canonical or legacy.
	 * @param string|null          $actor          The creating identity.
	 * @param Closure|null         $mayReadBlocker Answers whether the creating caller may read a blocker; null for a
	 *                                             trusted caller.
	 * @param DateTime|null        $createdAt      The moment the task was created, for a task carried over from
	 *                                             elsewhere; null stamps the task and its audit entry with now.
	 */
	public function __construct(
		public readonly array $data,
		public readonly ?string $actor,
		public readonly ?Closure $mayReadBlocker = null,
		public readonly ?DateTime $createdAt = null,
	) {
	}//end __construct()
}//end class
