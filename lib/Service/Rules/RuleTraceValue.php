<?php

/**
 * OpenRegister RuleTraceValue
 *
 * Renders the value a deciding operand read, for the rule run log.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * A read value as the string a {@see RuleTrace} carries, cut at its limit.
 *
 * A cut value ends in an ellipsis, so a reader can tell a truncated string
 * from a short one and does not compare a prefix against a full value.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleTraceValue {

	/**
	 * Render a read value for the log, cut at {@see RuleTrace::VALUE_LIMIT}.
	 *
	 * @param mixed $value The value the operand read.
	 *
	 * @return string The rendered value.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function render(mixed $value): string {
		$rendered = $this->uncut(value: $value);
		if (mb_strlen($rendered) <= RuleTrace::VALUE_LIMIT) {
			return $rendered;
		}

		return (mb_substr($rendered, 0, RuleTrace::VALUE_LIMIT) . '…');
	}//end render()

	/**
	 * The value as a string, before any cut.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The rendering.
	 */
	private function uncut(mixed $value): string {
		return match (true) {
			$value === true => 'true',
			$value === false => 'false',
			$value === null => 'null',
			is_scalar($value) === true => (string)$value,
			default => $this->encode(value: $value),
		};
	}//end uncut()

	/**
	 * A structured value as JSON, or a word saying it could not be rendered.
	 *
	 * `json_encode` answers `false` for a value it cannot encode, and `false`
	 * cast to a string is the empty string, which in a log column reads exactly
	 * like a property that was genuinely empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The JSON, or the word.
	 */
	private function encode(mixed $value): string {
		$json = json_encode($value);
		if ($json === false) {
			return 'unrenderable';
		}

		return $json;
	}//end encode()
}//end class
