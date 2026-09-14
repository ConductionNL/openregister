<?php

/**
 * OpenRegister PropertyCalculations
 *
 * Lifts the `calculation` key a property form forwards on a single schema
 * property into the one calculations map the validator and the evaluator
 * already understand, so an authored calculation and a hand-written
 * `x-openregister-calculations` entry travel the same path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

/**
 * The forwarding contract for a property-level calculation.
 *
 * A property may carry `calculation`, the JSON-AST sibling of the Twig
 * `computed` key. It is the key an administration surface writes, because the
 * AST is the engine an administrator authors and an auditor reads back; Twig
 * stays for schemas authored in code.
 *
 * The declaration is the same object the schema-level annotation holds
 * (`type` plus `expression`), so one validator, one evaluator and one cycle
 * check serve both. It materialises by default: the property exists on the
 * schema, so the value belongs in the stored object rather than being
 * recomputed on every read.
 */
final class PropertyCalculations {

	/**
	 * The property key an administration surface forwards a calculation under.
	 */
	public const PROPERTY_KEY = 'calculation';

	/**
	 * Collect the property-level declarations out of a schema's properties.
	 *
	 * @param array<string, mixed> $properties The schema's `properties` map.
	 *
	 * @return array<string, array<string, mixed>> Declarations keyed by property name.
	 *
	 * @spec openspec/changes/computed-values-by-json-ast/specs/computed-fields/spec.md
	 */
	public function fromProperties(array $properties): array {
		$collected = [];
		foreach ($properties as $name => $definition) {
			if (is_string($name) === false || $name === '' || is_array($definition) === false) {
				continue;
			}

			$declaration = ($definition[self::PROPERTY_KEY] ?? null);
			if (is_array($declaration) === false) {
				continue;
			}

			// `materialise` is true unless the author says otherwise: the
			// property is declared on the schema, so its value is stored.
			$declaration['materialise'] = ($declaration['materialise'] ?? true);
			$collected[$name] = $declaration;
		}

		return $collected;
	}//end fromProperties()

	/**
	 * Merge the forwarded declarations over the schema-level annotation.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-calculations` map (may be empty).
	 * @param array<string, mixed> $properties The schema's `properties` map.
	 *
	 * @return array<string, mixed> The one calculations map, or an empty array when neither declares anything.
	 *
	 * @spec openspec/changes/computed-values-by-json-ast/specs/computed-fields/spec.md
	 */
	public function merge(array $annotation, array $properties): array {
		return array_merge($annotation, $this->fromProperties(properties: $properties));
	}//end merge()

	/**
	 * The names declared in both places at once.
	 *
	 * A name declared twice has two expressions and no rule says which wins, so
	 * the save refuses rather than picking one.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-calculations` map.
	 * @param array<string, mixed> $properties The schema's `properties` map.
	 *
	 * @return array<int, string> The duplicated names.
	 *
	 * @spec openspec/changes/computed-values-by-json-ast/specs/computed-fields/spec.md
	 */
	public function duplicates(array $annotation, array $properties): array {
		return array_values(
			array_intersect(
				array_map('strval', array_keys($annotation)),
				array_map('strval', array_keys($this->fromProperties(properties: $properties)))
			)
		);
	}//end duplicates()
	/**
	 * Pick out the errors a forwarded property declaration is answerable for.
	 *
	 * An error names the calculation it belongs to, so attribution is by name:
	 * an error mentioning a forwarded name blocks the save, and the rest stay
	 * advisory so a register file cannot break its own import. A duplicate
	 * declaration blocks on its own code, and `dependsOn` is never blocking
	 * because it is a note to the author, not a broken expression.
	 *
	 * @param array<int, array{code: string, message: string}> $errors Every validation error.
	 * @param array<int, string> $forwarded The names declared on a property.
	 *
	 * @return array<int, array{code: string, message: string}> The blocking errors, in input order.
	 *
	 * @spec openspec/changes/computed-values-by-json-ast/specs/computed-fields/spec.md
	 */
	public function blockingErrors(array $errors, array $forwarded): array {
		$blocking = [];
		foreach ($errors as $error) {
			if ($error['code'] === 'calculation-dependson-ignored') {
				continue;
			}

			if ($error['code'] === 'calculation-duplicate-declaration') {
				$blocking[] = $error;
				continue;
			}

			foreach ($forwarded as $name) {
				// The validator quotes the calculation name in every message,
				// and a cycle message lists every name on the path.
				if (str_contains($error['message'], '"' . $name . '"') === true
					|| str_contains($error['message'], ' ' . $name . ' ->') === true
					|| str_contains($error['message'], '-> ' . $name) === true
				) {
					$blocking[] = $error;
					break;
				}
			}
		}

		return $blocking;
	}//end blockingErrors()
}//end class
