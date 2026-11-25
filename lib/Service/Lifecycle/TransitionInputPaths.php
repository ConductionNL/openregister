<?php

/**
 * OpenRegister TransitionInputPaths
 *
 * Reads a transition's declared `inputs` as paths, so an input may name a
 * field inside an object property (`parent.child`).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/transition-inputs-name-a-nested-field/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * The path rules of a transition's `inputs` allowlist.
 *
 * A declared `field` is a path: `reason` names a top-level property,
 * `municipalityFeedback.masRoute` names `masRoute` inside the object property
 * `municipalityFeedback`. A client posts the nested shape
 * (`{"municipalityFeedback": {"masRoute": "..."}}`); a literal dotted key in
 * the payload is unfolded into that shape first. A parent that is only reached
 * through dotted declarations accepts exactly its declared children; a parent
 * that is itself declared accepts its whole value, as before.
 *
 * Pure functions over arrays, no state.
 *
 * @spec openspec/changes/transition-inputs-name-a-nested-field/specs/object-lifecycle/spec.md
 */
final class TransitionInputPaths {

	/**
	 * Unfold literal dotted payload keys into the nested shape.
	 *
	 * `{"a.b": 1}` becomes `{"a": {"b": 1}}`; a nested value posted beside it
	 * is kept, the literal key's leaf wins on a clash.
	 *
	 * @param array<string, mixed> $data The caller-supplied payload.
	 *
	 * @return array<string, mixed> The payload with every dotted key unfolded.
	 *
	 * @spec openspec/changes/transition-inputs-name-a-nested-field/specs/object-lifecycle/spec.md
	 */
	public static function unfold(array $data): array {
		$unfolded = [];
		foreach ($data as $key => $value) {
			$key = (string)$key;
			if (str_contains($key, '.') === false) {
				$unfolded[$key] = self::mergeValue(existing: ($unfolded[$key] ?? null), value: $value);
				continue;
			}

			$unfolded = self::setPath(data: $unfolded, path: explode('.', $key), value: $value);
		}

		return $unfolded;
	}//end unfold()

	/**
	 * Name every payload path the declaration does not allow.
	 *
	 * @param array<string, bool>  $declared Map of declared field path to its `required` flag.
	 * @param array<string, mixed> $data     The unfolded payload.
	 *
	 * @return array<int, string> The undeclared paths, dotted, in payload order.
	 *
	 * @spec openspec/changes/transition-inputs-name-a-nested-field/specs/object-lifecycle/spec.md
	 */
	public static function undeclared(array $declared, array $data): array {
		return self::undeclaredUnder(paths: array_keys($declared), data: $data, prefix: '');
	}//end undeclared()

	/**
	 * Name every required path the payload leaves absent or empty-string.
	 *
	 * @param array<string, bool>  $declared Map of declared field path to its `required` flag.
	 * @param array<string, mixed> $data     The unfolded payload.
	 *
	 * @return array<int, string> The missing required paths, dotted.
	 *
	 * @spec openspec/changes/transition-inputs-name-a-nested-field/specs/object-lifecycle/spec.md
	 */
	public static function missing(array $declared, array $data): array {
		$missing = [];
		foreach ($declared as $path => $required) {
			if ($required === false) {
				continue;
			}

			$found = self::readPath(data: $data, path: explode('.', (string)$path));
			if ($found['present'] === false || $found['value'] === '') {
				$missing[] = (string)$path;
			}
		}

		return $missing;
	}//end missing()

	/**
	 * Merge accepted values into the object's data.
	 *
	 * A top-level key replaces the stored value, as before. A value under a
	 * parent reached only through dotted declarations is written leaf by leaf,
	 * so the parent's other stored fields survive.
	 *
	 * @param array<string, mixed> $objectData The stored object data.
	 * @param array<string, mixed> $accepted   The accepted, unfolded payload.
	 * @param array<string, bool>  $declared   Map of declared field path to its `required` flag.
	 *
	 * @return array<string, mixed> The object data with the accepted values in.
	 *
	 * @spec openspec/changes/transition-inputs-name-a-nested-field/specs/object-lifecycle/spec.md
	 */
	public static function merge(array $objectData, array $accepted, array $declared): array {
		foreach ($accepted as $key => $value) {
			$key = (string)$key;
			if (array_key_exists($key, $declared) === true || is_array($value) === false) {
				$objectData[$key] = $value;
				continue;
			}

			$stored = $objectData[$key] ?? [];
			if (is_array($stored) === false) {
				$stored = [];
			}

			$children = self::childPaths(paths: array_keys($declared), parent: $key);
			$objectData[$key] = self::merge(objectData: $stored, accepted: $value, declared: $children);
		}

		return $objectData;
	}//end merge()

	/**
	 * The undeclared paths of one payload level.
	 *
	 * @param array<int, string|int> $paths  The declared paths, relative to this level.
	 * @param array<string, mixed>   $data   The payload at this level.
	 * @param string                 $prefix The dotted path of this level, '' at the top.
	 *
	 * @return array<int, string> The undeclared paths, dotted from the top.
	 */
	private static function undeclaredUnder(array $paths, array $data, string $prefix): array {
		$paths = array_map('strval', $paths);
		$unknown = [];
		foreach ($data as $key => $value) {
			$key = (string)$key;
			if (in_array($key, $paths, true) === true) {
				continue;
			}

			$children = self::childPaths(paths: $paths, parent: $key);
			if ($children === [] || is_array($value) === false || ($value !== [] && array_is_list($value) === true)) {
				$unknown[] = $prefix.$key;
				continue;
			}

			$unknown = array_merge(
				$unknown,
				self::undeclaredUnder(paths: array_keys($children), data: $value, prefix: $prefix.$key.'.')
			);
		}

		return $unknown;
	}//end undeclaredUnder()

	/**
	 * The declared paths below one parent key, relative to it.
	 *
	 * @param array<int, string|int> $paths  The declared paths at this level.
	 * @param string                 $parent The parent key.
	 *
	 * @return array<string, bool> The child paths (values unused, kept as a map for merge()).
	 */
	private static function childPaths(array $paths, string $parent): array {
		$children = [];
		$prefix = $parent.'.';
		foreach ($paths as $path) {
			$path = (string)$path;
			if (str_starts_with($path, $prefix) === true && strlen($path) > strlen($prefix)) {
				$children[substr($path, strlen($prefix))] = true;
			}
		}

		return $children;
	}//end childPaths()

	/**
	 * Read one path from the payload.
	 *
	 * @param array<string, mixed> $data The payload.
	 * @param array<int, string>   $path The path segments.
	 *
	 * @return array{present: bool, value: mixed} Whether the path is present, and its value.
	 */
	private static function readPath(array $data, array $path): array {
		$current = $data;
		foreach ($path as $segment) {
			if (is_array($current) === false || array_key_exists($segment, $current) === false) {
				return ['present' => false, 'value' => null];
			}

			$current = $current[$segment];
		}

		return ['present' => true, 'value' => $current];
	}//end readPath()

	/**
	 * Write one path into a payload, creating parents as needed.
	 *
	 * @param array<string, mixed> $data  The payload.
	 * @param array<int, string>   $path  The path segments.
	 * @param mixed                $value The leaf value.
	 *
	 * @return array<string, mixed> The payload with the leaf set.
	 */
	private static function setPath(array $data, array $path, mixed $value): array {
		$head = array_shift($path);
		if ($path === []) {
			$data[$head] = $value;
			return $data;
		}

		$child = $data[$head] ?? [];
		if (is_array($child) === false) {
			$child = [];
		}

		$data[$head] = self::setPath(data: $child, path: $path, value: $value);
		return $data;
	}//end setPath()

	/**
	 * Combine a nested value with one already unfolded under the same key.
	 *
	 * @param mixed $existing The value unfolded so far, null when none.
	 * @param mixed $value    The value posted under the plain key.
	 *
	 * @return mixed The combined value: arrays merge (unfolded leaves win), anything else replaces.
	 */
	private static function mergeValue(mixed $existing, mixed $value): mixed {
		if (is_array($existing) === true && is_array($value) === true) {
			return array_replace_recursive($value, $existing);
		}

		return $value;
	}//end mergeValue()
}//end class
