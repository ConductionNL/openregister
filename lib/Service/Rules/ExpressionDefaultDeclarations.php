<?php

/**
 * OpenRegister ExpressionDefaultDeclarations
 *
 * Refuses an expression default the engine could never run, at schema save.
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
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * The schema-save check behind {@see ExpressionDefaultResolver::validateDeclarations()}.
 *
 * It needs no evaluator: the shape check reads one constant operator table,
 * the same walk the evaluator dispatches on.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ExpressionDefaultDeclarations {

	/**
	 * The errors of every declared expression default, at most one per property.
	 *
	 * @param array<string, mixed> $properties The schema's properties block.
	 *
	 * @return array<int, array{code: string, message: string}> The errors, empty when sound.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function errors(array $properties): array {
		$errors = [];
		foreach ($properties as $name => $property) {
			if (is_array($property) === false || array_key_exists(ExpressionDefaultResolver::ANNOTATION, $property) === false) {
				continue;
			}

			$error = $this->errorOf(name: (string)$name, property: $property);
			if ($error !== null) {
				$errors[] = $error;
			}
		}

		return $errors;
	}//end errors()

	/**
	 * The error of one property's declaration, or null when it is sound.
	 *
	 * @param string               $name     The property.
	 * @param array<string, mixed> $property Its declaration, carrying the annotation.
	 *
	 * @return array{code: string, message: string}|null The error.
	 */
	private function errorOf(string $name, array $property): ?array {
		if ($this->isWellFormed(expression: $property[ExpressionDefaultResolver::ANNOTATION]) === false) {
			$message = 'Property "%s": %s must be a JSON-AST expression such as '
				. '{"dateAdd": [{"prop": "ontvangstdatum"}, 42, "days"]}.';
			return $this->error(code: ExpressionDefaultResolver::CODE_MALFORMED, message: sprintf($message, $name, ExpressionDefaultResolver::ANNOTATION));
		}

		if (array_key_exists('default', $property) === true && $property['default'] !== null) {
			$message = 'Property "%s" declares both a literal "default" and %s, and no rule says '
				. 'which wins. Keep one.';
			return $this->error(code: ExpressionDefaultResolver::CODE_TWO_DEFAULTS, message: sprintf($message, $name, ExpressionDefaultResolver::ANNOTATION));
		}

		return null;
	}//end errorOf()

	/**
	 * Whether a declared expression is a non-empty, well-formed JSON-AST node.
	 *
	 * @param mixed $expression The declared expression.
	 *
	 * @return bool True when the engine could run it.
	 */
	private function isWellFormed(mixed $expression): bool {
		return is_array($expression) === true
			&& $expression !== []
			&& ConditionDialect::isWellFormedAst(node: $expression) === true;
	}//end isWellFormed()

	/**
	 * Shape one error.
	 *
	 * @param string $code    One of the ExpressionDefaultResolver CODE_ constants.
	 * @param string $message The sentence naming the property and what is wrong.
	 *
	 * @return array{code: string, message: string} The error.
	 */
	private function error(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end error()
}//end class
