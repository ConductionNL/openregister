<?php

/**
 * What a performer submitted with a completion beyond the outcome: form answers and stored evidence.
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
 * The submitted answer fields and the references to files already stored for
 * one completion; both absent for a plain completion.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskAnswerPayload {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null             $responses The validated form responses, when a form was answered.
	 * @param array<int, array<string, mixed>>|null $evidence  Stored file references, when given.
	 */
	public function __construct(
		public readonly array|null $responses = null,
		public readonly array|null $evidence = null,
	) {
	}//end __construct()
}//end class
