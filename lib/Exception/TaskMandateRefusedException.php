<?php

/**
 * A delegate or stand-in does not hold the mandate a task requires.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * The person a task would go to lacks its mandate (422). The message names
 * the verb and the person, never the subject or the rule that was missing.
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
 */
class TaskMandateRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $verb The mandate verb that was not held.
	 * @param string $uid  The person who does not hold it.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
	 */
	public function __construct(
		private readonly string $verb,
		private readonly string $uid,
	) {
		parent::__construct(
			message: sprintf("'%s' does not hold the mandate '%s' this task requires.", $uid, $verb)
		);
	}//end __construct()

	/**
	 * The mandate verb that was not held.
	 *
	 * @return string The verb.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
	 */
	public function getVerb(): string {
		return $this->verb;
	}//end getVerb()

	/**
	 * The person who does not hold it.
	 *
	 * @return string The user id.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
	 */
	public function getUid(): string {
		return $this->uid;
	}//end getUid()
}//end class
