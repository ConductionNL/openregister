<?php

/**
 * OpenRegister SetFieldsAction
 *
 * Built-in lifecycle action handler that stamps field values onto the
 * transitioning object. Backs the two most-declared action names across the
 * fleet's register.d: `set-fields` (parameters ARE the field map) and
 * `set-field` (field map under a `set` key). Supports the `@now` token,
 * resolved to an ISO-8601 UTC timestamp at execution time.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Lifecycle
 * @package  OCA\OpenRegister\Lifecycle\Action
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

namespace OCA\OpenRegister\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use RuntimeException;

/**
 * Stamp declared field values onto the transitioning object payload.
 *
 * Registered as the OpenRegister built-in for both `set-fields` and
 * `set-field`. Self-mutating: returns the payload with the declared fields
 * applied so the executor merges it back before persistence.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SetFieldsAction implements LifecycleActionInterface {
	/**
	 * Token that resolves to the current UTC time (ISO-8601) at execution time.
	 *
	 * @var string
	 */
	private const NOW_TOKEN = '@now';

	/**
	 * Apply the declared field map to the object payload.
	 *
	 * Accepts both authoring shapes:
	 *  - `set-fields`: the `actionParameters` map IS the field map
	 *    (e.g. `{ "submittedAt": "@now" }`).
	 *  - `set-field`: the field map lives under a `set` key
	 *    (e.g. `{ "set": { "submittedAt": "@now" } }`).
	 *
	 * @param array<string, mixed> $objectData Object payload after the lifecycle move.
	 * @param array<string, mixed> $previousData Object payload before the transition (unused).
	 * @param array<string, mixed> $parameters The declared `actionParameters` block.
	 * @param string $actionName The declared action name (`set-fields` or `set-field`).
	 *
	 * @return array<string, mixed> The payload with the declared fields stamped.
	 *
	 * @throws RuntimeException When no field map can be resolved from the parameters (declared but useless — fail loud).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$fields = $this->resolveFieldMap(parameters: $parameters);
		$this->assertDeclaresFields(fields: $fields, actionName: $actionName);
		return $this->stampFields(objectData: $objectData, fields: $fields);
	}//end execute()

	/**
	 * Refuse an action whose field map is empty: declared but useless, so fail loud.
	 *
	 * @param array<string, mixed> $fields     The resolved field map.
	 * @param string               $actionName The declared action name.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the field map is empty.
	 */
	private function assertDeclaresFields(array $fields, string $actionName): void {
		if ($fields === []) {
			throw new RuntimeException(
				sprintf('Lifecycle action "%s" declares no fields to set.', $actionName)
			);
		}
	}//end assertDeclaresFields()

	/**
	 * Stamp the resolved field values onto the payload.
	 *
	 * @param array<string, mixed> $objectData The object payload.
	 * @param array<string, mixed> $fields     The field → declared value map.
	 *
	 * @return array<string, mixed> The payload with the fields stamped.
	 */
	private function stampFields(array $objectData, array $fields): array {
		foreach ($fields as $key => $value) {
			$objectData[(string)$key] = $this->resolveValue(value: $value);
		}

		return $objectData;
	}//end stampFields()

	/**
	 * Resolve the field map from either authoring shape.
	 *
	 * @param array<string, mixed> $parameters The declared `actionParameters` block.
	 *
	 * @return array<string, mixed> The field → value map (possibly empty).
	 */
	private function resolveFieldMap(array $parameters): array {
		// `set-field` shape: fields under a `set` key.
		if (isset($parameters['set']) === true && is_array($parameters['set']) === true) {
			return $parameters['set'];
		}

		// `set-fields` shape: the parameters map IS the field map.
		return $parameters;
	}//end resolveFieldMap()

	/**
	 * Resolve a declared value, expanding the `@now` token.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return mixed The resolved value.
	 */
	private function resolveValue(mixed $value): mixed {
		if ($value === self::NOW_TOKEN) {
			return gmdate('Y-m-d\TH:i:s\Z');
		}

		return $value;
	}//end resolveValue()
}//end class
