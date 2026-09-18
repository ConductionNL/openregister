<?php

/**
 * OpenRegister JobRunRefusedException
 *
 * Thrown when the operations console will not start a job.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * A run the console refused, with the reason and the run that holds the job.
 *
 * The details matter as much as the reason: "already running" without the run
 * it collided with leaves the administrator pressing the button again, which
 * is the loop D-2 exists to end.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
 */
class JobRunRefusedException extends Exception {

	/**
	 * The stable reason word.
	 *
	 * @var string
	 */
	private readonly string $reason;

	/**
	 * What the refusal collided with.
	 *
	 * @var array<string, mixed>
	 */
	private readonly array $details;

	/**
	 * Constructor.
	 *
	 * @param string               $message What went wrong, for the reader.
	 * @param string               $reason  The stable reason word, for the caller.
	 * @param array<string, mixed> $details What the refusal collided with.
	 */
	public function __construct(string $message, string $reason, array $details = []) {
		parent::__construct($message);
		$this->reason = $reason;
		$this->details = $details;

	}//end __construct()

	/**
	 * The stable reason word.
	 *
	 * @return string The reason.
	 */
	public function getReason(): string {
		return $this->reason;

	}//end getReason()

	/**
	 * What the refusal collided with.
	 *
	 * @return array<string, mixed> The details.
	 */
	public function getDetails(): array {
		return $this->details;

	}//end getDetails()
}//end class
