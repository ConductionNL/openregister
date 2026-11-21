<?php

/**
 * OpenRegister Referential Integrity Exception
 *
 * Exception thrown when an object deletion is blocked by referential integrity constraints.
 * Contains the full DeletionAnalysis with blocker details for structured API error responses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use OCA\OpenRegister\Dto\DeletionAnalysis;

/**
 * Exception thrown when deletion is blocked by referential integrity constraints.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 */
class ReferentialIntegrityException extends Exception {

	/**
	 * Constructor for ReferentialIntegrityException.
	 *
	 * The exception code is always 0.
	 *
	 * @param DeletionAnalysis $analysis The deletion analysis with blocker information.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(private readonly DeletionAnalysis $analysis) {
		$blockerCount = 0;
		foreach ($analysis->blockers as $blocker) {
			$blockerCount += (int) ($blocker['count'] ?? 1);
		}

		parent::__construct(message: "Cannot delete object: {$blockerCount} dependent object(s) block deletion", code: 0);
	}//end __construct()

	/**
	 * Get the deletion analysis.
	 *
	 * @return DeletionAnalysis The analysis containing blocker and target details.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getAnalysis(): DeletionAnalysis {
		return $this->analysis;
	}//end getAnalysis()

	/**
	 * Get a structured error response body suitable for JSON API responses.
	 *
	 * @return array The structured error response with error code, message, and blockers.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function toResponseBody(): array {
		return [
			'error' => 'DELETION_BLOCKED',
			'message' => $this->getMessage(),
			'blockers' => $this->analysis->blockers,
		];
	}//end toResponseBody()
}//end class
