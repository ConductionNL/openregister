<?php

/**
 * Substitutes `{{dotted.path}}` placeholders in a step's configured values.
 *
 * Every node that takes authored values resolves them against the item —
 * `openconnector.source-call` its endpoint, body and query, `hermiq.agent-step`
 * its prompt, `openregister.object-write` its fields. This is that rule, in one
 * place, for OpenRegister's own nodes.
 *
 * It is deliberately NOT OpenConnector's `FlowTemplate`. That class belongs to
 * an app which may not be installed, and OpenRegister's built-in nodes must not
 * depend on one that isn't.
 *
 * A value that is EXACTLY one placeholder keeps the resolved value's TYPE, so a
 * number stays a number and a list stays a list. That matters wherever the
 * value is compared rather than printed: `"7"` and `7` are not the same filter.
 * Anything else is substituted inline and stringified.
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
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Renders authored values against an item's record.
 */
final class FlowValueTemplate {

	/**
	 * The placeholder shape: `{{ dotted.path }}`.
	 *
	 * @var string
	 */
	private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}/';

	/**
	 * The same shape, anchored — a value that is ONLY a placeholder.
	 *
	 * @var string
	 */
	private const WHOLE = '/^\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}$/';

	/**
	 * Render a value and REPORT the placeholders that came out empty.
	 *
	 * {@see render()} cannot fail. A path the item does not carry resolves to
	 * null and is substituted as the empty string, so `"ZZ issue-lock {{repo}}"`
	 * silently becomes `"ZZ issue-lock "` and a caller that meant to constrain
	 * something ends up constraining something else. That is invisible wherever
	 * the rendered value is a QUESTION rather than a payload: an empty filter
	 * value matches nothing, the step returns zero rows, and the run reports
	 * success having done nothing.
	 *
	 * This variant renders exactly as `render()` does and additionally returns
	 * every placeholder path that could not be filled, so a caller for whom an
	 * empty value is meaningless can refuse rather than proceed. It does not
	 * decide that itself: `render()` stays the default, because omitting a
	 * FIELD legitimately narrows what is written.
	 *
	 * A path counts as unfilled when the item does not carry it, when it
	 * carries null, or when it carries the empty string. All three produce the
	 * same empty rendering, so telling them apart would only let one of them
	 * through.
	 *
	 * @param mixed $value The authored value.
	 * @param array $json The item's record.
	 *
	 * @return array{value: mixed, unresolved: array<int, string>} The rendered
	 *                                                             value, and the placeholder paths that rendered empty.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function renderTracked(mixed $value, array $json): array {
		$rendered = self::renderValue(value: $value, json: $json);

		return [
			'value' => $rendered['value'],
			'unresolved' => array_values(array_unique($rendered['unresolved'])),
		];

	}//end renderTracked()

	/**
	 * Render a value — scalar, list or map — against an item's record.
	 *
	 * @param mixed $value The authored value.
	 * @param array $json The item's record.
	 *
	 * @return mixed The rendered value.
	 */
	public static function render(mixed $value, array $json): mixed {
		return self::renderValue(value: $value, json: $json)['value'];

	}//end render()

	/**
	 * Render recursively, alongside the placeholder paths that came out empty.
	 *
	 * A value that is not a string or an array is returned as it is.
	 *
	 * @param mixed $value The authored value.
	 * @param array $json The item's record.
	 *
	 * @return array{value: mixed, unresolved: array<int, string>} The rendering.
	 */
	private static function renderValue(mixed $value, array $json): array {
		if (is_array($value) === true) {
			return self::renderEach(values: $value, json: $json);
		}

		if (is_string($value) === false) {
			return ['value' => $value, 'unresolved' => []];
		}

		return self::renderString(value: $value, json: $json);

	}//end renderValue()

	/**
	 * Render every entry of a list or map, keeping its keys.
	 *
	 * @param array $values The authored entries.
	 * @param array $json   The item's record.
	 *
	 * @return array{value: array, unresolved: array<int, string>} The rendering.
	 */
	private static function renderEach(array $values, array $json): array {
		$rendered = [];
		$unresolved = [];
		foreach ($values as $key => $entry) {
			$part = self::renderValue(value: $entry, json: $json);
			$rendered[$key] = $part['value'];
			$unresolved = array_merge($unresolved, $part['unresolved']);
		}

		return ['value' => $rendered, 'unresolved' => $unresolved];

	}//end renderEach()

	/**
	 * Render one string.
	 *
	 * A string that is ONLY a placeholder keeps the resolved value's type, so
	 * a counter stays a number and a list stays a list; any other string has
	 * each placeholder substituted as text.
	 *
	 * @param string $value The authored string.
	 * @param array  $json  The item's record.
	 *
	 * @return array{value: mixed, unresolved: array<int, string>} The rendering.
	 */
	private static function renderString(string $value, array $json): array {
		$whole = [];
		if (preg_match(self::WHOLE, $value, $whole) === 1) {
			return self::resolve(path: $whole[1], json: $json);
		}

		return self::interpolate(value: $value, json: $json);

	}//end renderString()

	/**
	 * Substitute every placeholder in a string as text.
	 *
	 * An array value is substituted as its JSON encoding.
	 *
	 * @param string $value The authored string.
	 * @param array  $json  The item's record.
	 *
	 * @return array{value: string, unresolved: array<int, string>} The rendering.
	 */
	private static function interpolate(string $value, array $json): array {
		$unresolved = [];
		$text = (string)preg_replace_callback(
			self::PLACEHOLDER,
			static function (array $matches) use ($json, &$unresolved): string {
				$part = self::resolve(path: $matches[1], json: $json);
				$unresolved = array_merge($unresolved, $part['unresolved']);
				return self::asText(value: $part['value']);
			},
			$value
		);

		return ['value' => $text, 'unresolved' => $unresolved];

	}//end interpolate()

	/**
	 * The value at one placeholder path, and the path when it came out empty.
	 *
	 * @param string $path The dotted path.
	 * @param array  $json The item's record.
	 *
	 * @return array{value: mixed, unresolved: array<int, string>} The resolution.
	 */
	private static function resolve(string $path, array $json): array {
		$value = self::valueAt(path: $path, json: $json);
		$unresolved = [];
		if (self::isEmptyValue(value: $value) === true) {
			$unresolved[] = $path;
		}

		return ['value' => $value, 'unresolved' => $unresolved];

	}//end resolve()

	/**
	 * A resolved value as it reads inside a longer string.
	 *
	 * @param mixed $value The resolved value.
	 *
	 * @return string The text: JSON for an array, the string cast otherwise.
	 */
	private static function asText(mixed $value): string {
		if (is_array($value) === true) {
			return (string)json_encode($value);
		}

		return (string)$value;

	}//end asText()

	/**
	 * Whether a resolved value renders as nothing at all.
	 *
	 * `false`, `0` and `[]` are deliberately NOT empty: they are answers, and a
	 * whole-value placeholder keeps their type, so `{"enabled": "{{wanted}}"}`
	 * resolving to `false` is a real constraint rather than a missing one.
	 *
	 * @param mixed $value The resolved value.
	 *
	 * @return boolean Whether it renders as nothing.
	 */
	private static function isEmptyValue(mixed $value): bool {
		return ($value === null || $value === '');
	}//end isEmptyValue()

	/**
	 * The value at a dotted path, or null when the path is absent.
	 *
	 * @param string $path The dotted path.
	 * @param array $json The item's record.
	 *
	 * @return mixed The value, or null.
	 */
	private static function valueAt(string $path, array $json): mixed {
		$cursor = $json;
		foreach (explode('.', $path) as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end valueAt()
}//end class
