<?php

/**
 * OpenRegister validation verdict
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * What one administered validation decided about a write, as the run log records it.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
final class ValidationVerdict {
	/**
	 * Name the verdict.
	 *
	 * @param array<string, mixed> $entry   The outcome entry: validation, severity, properties, message, unevaluable.
	 * @param string               $verdict The verdict to record when the check could be evaluated.
	 */
	public function __construct(
		public readonly array $entry,
		public readonly string $verdict,
	) {
	}//end __construct()

	/**
	 * The validation's declared name, empty when the entry carries none.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function validation(): string {
		return (string)($this->entry['validation'] ?? '');
	}//end validation()

	/**
	 * The trace a run row records: an unevaluable check is an error, not a verdict.
	 *
	 * @return RuleTrace The trace.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function trace(): RuleTrace {
		$verdict = $this->verdict;
		if (($this->entry['unevaluable'] ?? false) === true) {
			$verdict = RuleVocabulary::VERDICT_ERROR;
		}

		return new RuleTrace(
			verdict: $verdict,
			operand: implode(', ', ($this->entry['properties'] ?? [])),
			message: (string)($this->entry['message'] ?? '')
		);
	}//end trace()
}//end class
