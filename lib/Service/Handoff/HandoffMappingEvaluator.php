<?php

/**
 * OpenRegister Handoff Mapping Evaluator
 *
 * Evaluates a validated `x-openregister-handoff` mapping against a source
 * object's data, producing the kind-contract field values for a handoff
 * (ADR-051). Exactly five expression kinds exist in v1:
 *
 * - `from`        — copy the named source property (optional `default`).
 * - `const`       — a literal value.
 * - `template`    — `{{prop}}` interpolation over the source data using the
 *                   existing OR convention (HTML-escaped, unknown → '').
 * - `semanticRef` — carry the ADR-048 semantic reference (the UUID string)
 *                   from the named source property; NEVER dereferences or
 *                   copies the referenced object's data.
 * - `provenance`  — the engine-filled source pointer
 *                   `{app, register, schema, uuid}`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

/**
 * Evaluate handoff mapping expressions to contract-field values.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Requirement: HandoffService executes conversions on top of SemanticTypeResolver)
 */
class HandoffMappingEvaluator {
	/**
	 * Evaluate a full mapping.
	 *
	 * @param array<string, mixed> $mapping The declared mapping (contract field → expression;
	 *                                      each expression is validated as an array at runtime).
	 * @param array<string, mixed> $sourceData The source object's data.
	 * @param array<string, mixed> $provenance The engine-filled source pointer `{app, register, schema, uuid}`.
	 *
	 * @return array<string, mixed> Contract field → evaluated value (fields evaluating to null are omitted).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Semantic references are carried, not copied)
	 */
	public function evaluate(array $mapping, array $sourceData, array $provenance): array {
		$result = [];
		foreach ($mapping as $field => $expression) {
			if (is_array($expression) === false) {
				continue;
			}

			$value = $this->evaluateExpression(
				expression: $expression,
				sourceData: $sourceData,
				provenance: $provenance
			);
			if ($value !== null) {
				$result[(string)$field] = $value;
			}
		}

		return $result;
	}//end evaluate()

	/**
	 * Evaluate one mapping expression.
	 *
	 * @param array<string, mixed> $expression The expression (exactly one of the five kinds).
	 * @param array<string, mixed> $sourceData The source object's data.
	 * @param array<string, mixed> $provenance The engine-filled source pointer.
	 *
	 * @return mixed The evaluated value, or null when the expression yields nothing
	 *               (e.g. `from` on an absent property without a `default`).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: `x-openregister-handoff` declarative dialect)
	 */
	private function evaluateExpression(array $expression, array $sourceData, array $provenance): mixed {
		if (array_key_exists('const', $expression) === true) {
			return $expression['const'];
		}

		if (array_key_exists('provenance', $expression) === true) {
			return $provenance;
		}

		if (array_key_exists('template', $expression) === true) {
			return $this->interpolate(
				template: (string)$expression['template'],
				data: $sourceData
			);
		}

		if (array_key_exists('semanticRef', $expression) === true) {
			return $this->carryReference(
				property: (string)$expression['semanticRef'],
				sourceData: $sourceData
			);
		}

		if (array_key_exists('from', $expression) === true) {
			$value = ($sourceData[(string)$expression['from']] ?? null);
			if ($value === null && array_key_exists('default', $expression) === true) {
				return $expression['default'];
			}

			return $value;
		}

		return null;
	}//end evaluateExpression()

	/**
	 * Carry an ADR-048 semantic reference across as a reference.
	 *
	 * The source property holds either a UUID string or a reference envelope
	 * whose identifying UUID we extract — the referenced object's DATA is
	 * never read, dereferenced, or copied (contract scenario "Semantic
	 * references are carried, not copied").
	 *
	 * @param string $property The source property holding the reference.
	 * @param array<string, mixed> $sourceData The source object's data.
	 *
	 * @return string|null The reference UUID/identifier, or null when absent.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Semantic references are carried, not copied)
	 */
	private function carryReference(string $property, array $sourceData): ?string {
		$value = ($sourceData[$property] ?? null);
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		if (is_array($value) === true) {
			foreach (['uuid', 'id', '@id'] as $key) {
				$candidate = ($value[$key] ?? null);
				if (is_string($candidate) === true && $candidate !== '') {
					return $candidate;
				}
			}
		}

		return null;
	}//end carryReference()

	/**
	 * `{{prop}}` interpolation over the source data, HTML-escaped, unknown or
	 * non-scalar properties rendering as '' — the existing OR convention
	 * ({@see \OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher}).
	 *
	 * @param string $template The template string.
	 * @param array<string, mixed> $data The source object's data.
	 *
	 * @return string The interpolated string.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: `x-openregister-handoff` declarative dialect)
	 */
	private function interpolate(string $template, array $data): string {
		$result = preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
			function (array $matches) use ($data): string {
				$key = $matches[1];
				if (array_key_exists($key, $data) === false || is_scalar($data[$key]) === false) {
					return '';
				}

				return htmlspecialchars((string)$data[$key], ENT_QUOTES, 'UTF-8');
			},
			$template
		);

		return ($result ?? $template);
	}//end interpolate()
}//end class
