<?php

/**
 * OpenRegister RepairRefusedException
 *
 * Thrown when a consistency repair cannot be performed as asked.
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
 * A repair the instance will not perform, with the reason on the wire.
 *
 * The reason is a stable machine-readable word, not the sentence: the sentence
 * is for the reader and may be translated or reworded, and a caller that
 * branches on prose breaks the first time somebody improves it.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
 */
class RepairRefusedException extends Exception {

	/**
	 * The stable reason word.
	 *
	 * @var string
	 */
	private readonly string $reason;

	/**
	 * Constructor.
	 *
	 * @param string $message What went wrong, for the reader.
	 * @param string $reason  The stable reason word, for the caller.
	 */
	public function __construct(string $message, string $reason) {
		parent::__construct($message);
		$this->reason = $reason;

	}//end __construct()

	/**
	 * The stable reason word.
	 *
	 * @return string The reason.
	 */
	public function getReason(): string {
		return $this->reason;

	}//end getReason()
}//end class
