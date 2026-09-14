<?php

/**
 * BulkJobRefusedException.
 *
 * Thrown when a bulk job is refused before anything is written: a selection
 * above the instance ceiling, a selection spanning more than one schema
 * version under the homogeneity guard, or a commit with no justification
 * where the action requires one. The controller maps it to HTTP 422 and
 * hands the caller the details, because "refused" with no numbers in it is
 * the answer the operator cannot act on.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * Signals a bulk job that was refused, with the numbers behind the refusal.
 */
class BulkJobRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message The refusal, in a sentence the operator reads.
	 * @param string $reason A machine-readable refusal code.
	 * @param array<string, mixed> $details The numbers behind the refusal.
	 */
	public function __construct(
		string $message,
		private readonly string $reason = 'refused',
		private readonly array $details = [],
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The machine-readable refusal code.
	 *
	 * @return string The reason code.
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()

	/**
	 * The numbers behind the refusal.
	 *
	 * @return array<string, mixed> The details.
	 */
	public function getDetails(): array {
		return $this->details;
	}//end getDetails()
}//end class
