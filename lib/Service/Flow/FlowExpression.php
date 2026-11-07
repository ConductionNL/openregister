<?php

/**
 * Evaluates a flow's expressions against one item.
 *
 * Expressions are how one step reads another's output — the thing a condition
 * branches on, and the thing a field is set from. This uses JSONLogic
 * (`jwadhams/json-logic-php`), which openconnector already relies on for
 * synchronisation and endpoint conditions. One expression language for the
 * fleet, not two.
 *
 * WHY NOT A CODE NODE
 * -------------------
 * A JavaScript expression engine, which is what n8n uses, means running
 * user-authored code inside the Nextcloud process — full `OC\Server`, database
 * and filesystem access from a text field in a flow editor. That is not a
 * trade worth making, so JSONLogic is the ceiling here by decision, not by
 * omission. The route to the things it genuinely cannot express (loops with
 * state, parsing, crypto) is an optional sandboxed sidecar (#2066), never a
 * relaxation of this boundary.
 *
 * THE DATA IN SCOPE
 * -----------------
 * An expression sees a flat document, so `{"var": "json.status"}` reads the
 * current item's `status`:
 *
 *   json       the current item's record
 *   binary     the current item's attachments
 *   itemIndex  this item's position in the list
 *   itemCount  how many items the step received
 *   context    run-level metadata (run uuid, trigger, user)
 *   subject    the object the run is about
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use JWadhams\JsonLogic;
use Throwable;

/**
 * JSONLogic evaluation with a flow-shaped data context.
 */
class FlowExpression {

	/**
	 * Whether the custom operators have been registered this request.
	 *
	 * @var boolean
	 */
	private static bool $operatorsRegistered = false;

	/**
	 * Build the document an expression is evaluated against.
	 *
	 * @param array $item The current item.
	 * @param integer $itemIndex Its position in the list.
	 * @param integer $itemCount How many items the step received.
	 * @param array $context Run-level metadata.
	 * @param array $subject The object the run is about.
	 *
	 * @return array<string, mixed> The data document.
	 *
	 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
	 */
	public static function dataFor(
		array $item,
		int $itemIndex = 0,
		int $itemCount = 1,
		array $context = [],
		array $subject = [],
	): array {
		return [
			'json' => (array)($item[FlowItems::JSON] ?? []),
			'binary' => (array)($item[FlowItems::BINARY] ?? []),
			'itemIndex' => $itemIndex,
			'itemCount' => $itemCount,
			'context' => $context,
			'subject' => $subject,
		];

	}//end dataFor()

	/**
	 * Evaluate an expression.
	 *
	 * A malformed expression returns null rather than throwing. An author's
	 * typo should fail their condition, not abort a run mid-graph and leave
	 * side effects half applied — the failure belongs at save time, which is
	 * what {@see self::isValid()} is for.
	 *
	 * @param mixed $logic The JSONLogic expression.
	 * @param array $data The data document.
	 *
	 * @return mixed The result, or null when it could not be evaluated.
	 *
	 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
	 */
	public static function evaluate(mixed $logic, array $data) {
		self::registerOperators();

		try {
			return JsonLogic::apply($logic, $data);
		} catch (Throwable $e) {
			return null;
		}

	}//end evaluate()

	/**
	 * Evaluate an expression as a condition.
	 *
	 * JSONLogic returns a range of types; a condition needs exactly one bit.
	 * `apply()` returning null (an unevaluable expression) is FALSE, not true:
	 * a branch whose condition could not be evaluated must not be taken.
	 *
	 * @param mixed $logic The expression.
	 * @param array $data The data document.
	 *
	 * @return boolean Whether the condition holds.
	 *
	 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
	 */
	public static function isTrue(mixed $logic, array $data): bool {
		$result = self::evaluate(logic: $logic, data: $data);
		if ($result === null) {
			return false;
		}

		// JSONLogic's own truthiness: [] and '' and 0 are false.
		if (is_array($result) === true) {
			return $result !== [];
		}

		return (bool)$result;
	}//end isTrue()

	/**
	 * Whether an expression is well-formed enough to store.
	 *
	 * Called when a flow is saved, so a broken expression is caught in the
	 * editor instead of silently failing every run afterwards.
	 *
	 * @param mixed $logic The expression.
	 *
	 * @return boolean True when it evaluates against an empty document.
	 *
	 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
	 */
	public static function isValid(mixed $logic): bool {
		self::registerOperators();

		// Scalars are literals and always valid; only a rule object can be
		// malformed.
		if (is_array($logic) === false) {
			return true;
		}

		try {
			JsonLogic::apply($logic, self::dataFor(item: []));
			return true;
		} catch (Throwable $e) {
			return false;
		}

	}//end isValid()

	/**
	 * Add the operators flows need and JSONLogic does not ship, once per request.
	 *
	 * The operators themselves are {@see FlowExpressionOperators}.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
	 */
	private static function registerOperators(): void {
		if (self::$operatorsRegistered === true) {
			return;
		}

		self::$operatorsRegistered = true;
		foreach (FlowExpressionOperators::all() as $name => $operation) {
			JsonLogic::add_operation($name, $operation);
		}
	}//end registerOperators()
}//end class
