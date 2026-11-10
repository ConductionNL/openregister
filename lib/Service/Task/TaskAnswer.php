<?php

/**
 * What a performer answers when finishing a task: outcome, result and comment.
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
 * The performer's answer: the outcome, the free-text result, the comment
 * (mandatory on a rejecting outcome) and any submitted payload.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskAnswer {

	/**
	 * The submitted form answers and stored evidence.
	 *
	 * @var TaskAnswerPayload
	 */
	public readonly TaskAnswerPayload $payload;

	/**
	 * Constructor.
	 *
	 * @param string                 $outcome    The outcome.
	 * @param string|null            $resultText Free-text result.
	 * @param string|null            $comment    The comment, mandatory on a rejecting outcome.
	 * @param TaskAnswerPayload|null $payload    The submitted form answers and evidence; none when null.
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly string|null $resultText = null,
		public readonly string|null $comment = null,
		?TaskAnswerPayload $payload = null,
	) {
		$this->payload = ($payload ?? new TaskAnswerPayload());
	}//end __construct()
}//end class
