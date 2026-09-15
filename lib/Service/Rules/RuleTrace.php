<?php

/**
 * OpenRegister RuleTrace
 *
 * One evaluation's verdict and, when it did not fire, the operand that decided
 * it with the value that operand read.
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
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use JsonSerializable;

/**
 * Why a rule did or did not fire, in one bounded record.
 *
 * "Did not match" is not an answer a functional administrator can act on, so
 * per D-2 this carries the FIRST operand that made the condition false and the
 * value it read. That is one operand and one rendered value per evaluation,
 * never the whole expression tree: the engineer's full trace stays in the flow
 * run log, which already records what each node received and returned.
 *
 * The operand is null on a `fired` verdict and on a rule with no condition at
 * all, because in neither case did an operand decide anything.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
final class RuleTrace implements JsonSerializable {

	/**
	 * How much of a read value is kept.
	 *
	 * The value is rendered for a human reading a list, and it is written on
	 * every save a rule is evaluated on. A long text property would put a page
	 * of prose in a log row, so the rendering is cut here and the cut is
	 * visible in the string rather than silent.
	 *
	 * @var integer
	 */
	public const VALUE_LIMIT = 120;

	/**
	 * Constructor.
	 *
	 * @param string $verdict One of the RuleVocabulary VERDICT_ constants.
	 * @param string|null $operand The first operand that decided, as the author wrote it.
	 * @param string|null $operandValue The value that operand read, rendered.
	 * @param string|null $message The engine's own sentence, when it has one.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $verdict,
		private readonly ?string $operand = null,
		private readonly ?string $operandValue = null,
		private readonly ?string $message = null,
	) {
	}//end __construct()

	/**
	 * The verdict this evaluation reached.
	 *
	 * @return string One of the RuleVocabulary VERDICT_ constants.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getVerdict(): string {
		return $this->verdict;
	}//end getVerdict()

	/**
	 * The operand that decided, when one did.
	 *
	 * @return string|null The operand path, or null when nothing decided against the rule.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getOperand(): ?string {
		return $this->operand;
	}//end getOperand()

	/**
	 * The value the deciding operand read.
	 *
	 * @return string|null The rendered value, or null when no operand decided.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getOperandValue(): ?string {
		return $this->operandValue;
	}//end getOperandValue()

	/**
	 * The engine's own sentence about this evaluation.
	 *
	 * @return string|null The message, or null when the verdict says it all.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getMessage(): ?string {
		return $this->message;
	}//end getMessage()

	/**
	 * A trace for a rule that ran and did what it declares.
	 *
	 * @param string|null $message The engine's sentence, when it has one.
	 *
	 * @return self The trace.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public static function fired(?string $message = null): self {
		return new self(verdict: RuleVocabulary::VERDICT_FIRED, message: $message);
	}//end fired()

	/**
	 * A trace for a rule that could not be evaluated.
	 *
	 * @param string $message What went wrong.
	 *
	 * @return self The trace.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public static function errored(string $message): self {
		return new self(verdict: RuleVocabulary::VERDICT_ERROR, message: $message);
	}//end errored()

	/**
	 * Render a read value for the log, cut at the limit.
	 *
	 * A cut value ends in an ellipsis, so a reader can tell a truncated string
	 * from a short one and does not compare a prefix against a full value.
	 *
	 * @param mixed $value The value the operand read.
	 *
	 * @return string The rendered value.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public static function renderValue(mixed $value): string {
		if (is_bool($value) === true) {
			if ($value === true) {
				return 'true';
			}

			return 'false';
		}

		$rendered = match (true) {
			$value === null => 'null',
			is_scalar($value) === true => (string)$value,
			default => self::encode(value: $value),
		};

		if (mb_strlen($rendered) <= self::VALUE_LIMIT) {
			return $rendered;
		}

		return (mb_substr($rendered, 0, self::VALUE_LIMIT) . '…');
	}//end renderValue()

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
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private static function encode(mixed $value): string {
		$json = json_encode($value);
		if ($json === false) {
			return 'unrenderable';
		}

		return $json;
	}//end encode()

	/**
	 * The trace as an API surface returns it.
	 *
	 * @return array{verdict: string, operand: string|null, operandValue: string|null, message: string|null} The trace.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'verdict' => $this->verdict,
			'operand' => $this->operand,
			'operandValue' => $this->operandValue,
			'message' => $this->message,
		];
	}//end jsonSerialize()
}//end class
