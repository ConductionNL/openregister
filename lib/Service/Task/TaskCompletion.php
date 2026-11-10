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
	 * The outcome.
	 *
	 * @var string
	 */
	public readonly string $outcome;

	/**
	 * Free-text result.
	 *
	 * @var string|null
	 */
	public readonly string|null $resultText;

	/**
	 * The comment, mandatory on a rejecting outcome.
	 *
	 * @var string|null
	 */
	public readonly string|null $comment;

	/**
	 * The validated form responses, when a form was answered.
	 *
	 * @var array<string, mixed>|null
	 */
	public readonly array|null $responses;

	/**
	 * Stored file references, when given.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	public readonly array|null $evidence;

	/**
	 * Constructor.
	 *
	 * @param string      $verb   The verb: complete or resolve.
	 * @param string      $uuid   The task uuid.
	 * @param TaskAnswer  $answer The outcome, result, comment and payload.
	 * @param string|null $actor  The completing identity.
	 */
	public function __construct(
		public readonly string $verb,
		public readonly string $uuid,
		TaskAnswer $answer,
		public readonly string|null $actor,
	) {
		$this->outcome = $answer->outcome;
		$this->resultText = $answer->resultText;
		$this->comment = $answer->comment;
		$this->responses = $answer->payload->responses;
		$this->evidence = $answer->payload->evidence;
	}//end __construct()
}//end class
