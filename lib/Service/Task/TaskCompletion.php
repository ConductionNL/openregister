<?php

/**
 * A completion request: which task, by whom, with which outcome and payload.
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

/**
 * The arguments of {@see TaskService::complete()} and
 * {@see TaskService::resolve()}, carried together to the shared completion.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskCompletion {

	/**
	 * Constructor.
	 *
	 * @param string $verb The verb: complete or resolve.
	 * @param string $uuid The task uuid.
	 * @param string $outcome The outcome.
	 * @param string|null $resultText Free-text result.
	 * @param string|null $comment The comment, mandatory on a rejecting outcome.
	 * @param string|null $actor The completing identity.
	 * @param array<string, mixed>|null $responses The validated form responses, when a form was answered.
	 * @param array<int, array<string, mixed>>|null $evidence Stored file references, when given.
	 */
	public function __construct(
		public readonly string $verb,
		public readonly string $uuid,
		public readonly string $outcome,
		public readonly string|null $resultText,
		public readonly string|null $comment,
		public readonly string|null $actor,
		public readonly array|null $responses = null,
		public readonly array|null $evidence = null,
	) {
	}//end __construct()
}//end class
