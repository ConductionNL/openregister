<?php

/**
 * OpenRegister TemporalCalculations
 *
 * Reads a schema's materialised calculations and tells whether any of them
 * references the evaluation clock (`now`).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation\TemporalSweep
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation\TemporalSweep;

use OCA\OpenRegister\Db\Schema;

/**
 * Detect the `now`-dependent materialised calculations of a schema.
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
 */
final class TemporalCalculations {

	/**
	 * The schema's materialised calculations (empty when none declared).
	 *
	 * @param Schema $schema The schema to inspect.
	 *
	 * @return array<string, mixed> Materialised calculation specs by name.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function materialised(Schema $schema): array {
		$config = ($schema->getConfiguration() ?? []);
		$calcs = ($config['x-openregister-calculations'] ?? null);
		if (is_array($calcs) === false) {
			return [];
		}

		$materialised = [];
		foreach ($calcs as $name => $spec) {
			if (is_array($spec) === true && ($spec['materialise'] ?? false) === true) {
				$materialised[(string)$name] = $spec;
			}
		}

		return $materialised;
	}//end materialised()

	/**
	 * Whether any calculation's expression references the evaluation clock.
	 *
	 * @param array<string, mixed> $calculations The calculations to inspect.
	 *
	 * @return bool True when at least one expression is time-dependent.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function anyTemporal(array $calculations): bool {
		foreach ($calculations as $spec) {
			if (is_array($spec) === true && $this->referencesNow(expression: ($spec['expression'] ?? null)) === true) {
				return true;
			}
		}

		return false;
	}//end anyTemporal()

	/**
	 * Recursively detect the `now` operator key or the literal string `"now"`
	 * (accepted by `dateDiff`'s from/to arguments) in an expression tree.
	 *
	 * @param mixed $expression The (sub-)expression to scan.
	 *
	 * @return bool True when the expression references `now`.
	 */
	private function referencesNow(mixed $expression): bool {
		if (is_string($expression) === true) {
			return $expression === 'now';
		}

		if (is_array($expression) === false) {
			return false;
		}

		if (array_key_exists('now', $expression) === true) {
			return true;
		}

		return $this->anyReferencesNow(values: $expression);
	}//end referencesNow()

	/**
	 * Whether any of the given sub-expressions references `now`.
	 *
	 * @param array<int|string, mixed> $values The sub-expressions.
	 *
	 * @return bool True when one of them does.
	 */
	private function anyReferencesNow(array $values): bool {
		foreach ($values as $value) {
			if ($this->referencesNow(expression: $value) === true) {
				return true;
			}
		}

		return false;
	}//end anyReferencesNow()
}//end class
