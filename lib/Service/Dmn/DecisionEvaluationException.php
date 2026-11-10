<?php

/**
 * OpenRegister DMN Decision Evaluation Exception
 *
 * Typed exception carrying a stable `errorCode` for every way a decision
 * evaluation can fail. Callers (the REST controller, the workflow action
 * handler) MUST branch on `errorCode`, never on the message text.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

use RuntimeException;

/**
 * Typed evaluation failure with a stable machine-readable error code.
 *
 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
 */
class DecisionEvaluationException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $errorCode Stable machine-readable error code.
	 * @param array<string, mixed> $details Optional structured details (e.g. offending key/expression).
	 */
	public function __construct(
		private readonly string $errorCode,
		private readonly array $details = [],
	) {
		parent::__construct(message: $errorCode);
	}//end __construct()

	/**
	 * The stable error code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
	 */
	public function getErrorCode(): string {
		return $this->errorCode;
	}//end getErrorCode()

	/**
	 * Structured details for logging/debugging (never shown raw to end users).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
	 */
	public function getDetails(): array {
		return $this->details;
	}//end getDetails()
}//end class
