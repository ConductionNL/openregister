<?php

/**
 * OpenRegister RuleScope
 *
 * The objects a rule is about, and whether a rule passes a scope filter.
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
 * A rule's `scope: { property, value }`: the objects whose property holds that value.
 *
 * Descriptive only. A scoped rule still carries its own condition; the scope
 * lets the inventory list one kind of object's rules.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleScope {

	/**
	 * The scope a declaration carries, when it carries a usable one.
	 *
	 * @param array<string, mixed> $declaration The rule's declaration.
	 *
	 * @return array{property: string, value: string}|null The scope, or null for a rule about every object.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function fromDeclaration(array $declaration): ?array {
		$scope = ($declaration['scope'] ?? null);
		if (is_array($scope) === false) {
			return null;
		}

		$property = ($scope['property'] ?? null);
		$value = ($scope['value'] ?? null);
		if (is_string($property) === false || $property === '' || is_scalar($value) === false) {
			return null;
		}

		return ['property' => $property, 'value' => (string)$value];
	}//end fromDeclaration()

	/**
	 * Whether a rule with this scope passes a filter.
	 *
	 * An unscoped rule applies to every object and always passes. A scoped rule
	 * passes when the filter names its property with its value.
	 *
	 * @param array{property: string, value: string}|null $scope The rule's scope.
	 * @param array<string, mixed> $filter Property => value; empty means no filter.
	 *
	 * @return bool True when the rule is listed.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function admits(?array $scope, array $filter): bool {
		if ($filter === [] || $scope === null) {
			return true;
		}

		$wanted = ($filter[$scope['property']] ?? null);

		return is_scalar($wanted) === true && (string)$wanted === $scope['value'];
	}//end admits()
}//end class
