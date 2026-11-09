<?php

/**
 * A descriptor read as parts, because a whole file cannot be resolved (D-2).
 *
 * A case type is a hundred properties, a lifecycle and a permission matrix.
 * Treating the whole descriptor as one unit means a single local label change
 * blocks an upstream fix somewhere else entirely, and that is the failure the
 * change is written against: the municipality's extra field disappears, or
 * nobody dares upgrade and the instance freezes two releases behind.
 *
 * So the unit of comparison is the smallest addressable part: a dotted path to
 * a leaf. `properties.aanvrager.title` is a part. `properties.aanvrager` is
 * not, because two people can change different fields of it without disagreeing
 * about anything.
 *
 * 🔑 A LIST IS ONE LEAF. `required` is a set, not an ordered sequence, and
 * treating each index as a part would make inserting an entry at the front read
 * as a change to every entry after it. Lists of scalars are compared as SETS
 * (sorted before comparison) for the same reason; a list holding anything else
 * is compared as it stands, because its order may well carry meaning and this
 * class cannot know.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

/**
 * Flattening a descriptor to addressable parts, and putting it back together.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
class DescriptorParts {

	/**
	 * How deep a descriptor is walked before it is treated as a leaf.
	 *
	 * A bound rather than a belief: a descriptor is data an app ships, and a
	 * recursive walk with no ceiling is a stack overflow waiting for one badly
	 * generated file.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 12;

	/**
	 * The separator between path segments.
	 *
	 * @var string
	 */
	public const SEPARATOR = '.';

	/**
	 * A descriptor as a map of dotted path to leaf value.
	 *
	 * @param array<string, mixed> $descriptor The descriptor.
	 *
	 * @return array<string, mixed> Path to value.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function flatten(array $descriptor): array {
		return $this->flattenAt(descriptor: $descriptor, at: new DescriptorPath(prefix: '', depth: 0));
	}//end flatten()

	/**
	 * The parts of a (nested) descriptor found at one path.
	 *
	 * @param array<string, mixed> $descriptor The descriptor, or the map at this path.
	 * @param DescriptorPath       $at         The path so far and its depth.
	 *
	 * @return array<string, mixed> Path to value.
	 */
	private function flattenAt(array $descriptor, DescriptorPath $at): array {
		$parts = [];
		foreach ($descriptor as $key => $value) {
			$path = ($at->prefix . self::SEPARATOR . (string)$key);
			if ($at->prefix === '') {
				$path = (string)$key;
			}

			if ($this->descends(value: $value, depth: $at->depth) === true) {
				$parts += $this->flattenAt(descriptor: $value, at: new DescriptorPath(prefix: $path, depth: ($at->depth + 1)));
				continue;
			}

			$parts[$path] = $this->normalise(value: $value);
		}

		return $parts;
	}//end flattenAt()

	/**
	 * Whether the walk goes into a value: a non-empty map above the depth bound.
	 *
	 * @param mixed $value The value.
	 * @param int   $depth The depth of the value's path.
	 *
	 * @return bool True when the value is walked rather than kept as a leaf.
	 */
	private function descends(mixed $value, int $depth): bool {
		return is_array($value) === true
			&& $this->isList(value: $value) === false
			&& $value !== []
			&& $depth < self::MAX_DEPTH;
	}//end descends()

	/**
	 * Parts back into a descriptor.
	 *
	 * @param array<string, mixed> $parts Path to value.
	 *
	 * @return array<string, mixed> The descriptor.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function unflatten(array $parts): array {
		$descriptor = [];
		foreach ($parts as $path => $value) {
			$segments = explode(self::SEPARATOR, (string)$path);
			$cursor = &$descriptor;
			foreach ($segments as $index => $segment) {
				if ($index === (count($segments) - 1)) {
					$cursor[$segment] = $value;
					continue;
				}

				if (isset($cursor[$segment]) === false || is_array($cursor[$segment]) === false) {
					$cursor[$segment] = [];
				}

				$cursor = &$cursor[$segment];
			}

			unset($cursor);
		}

		return $descriptor;
	}//end unflatten()

	/**
	 * A value in the shape two of them are compared in.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The comparable value.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function normalise(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if ($this->isList(value: $value) === false) {
			ksort($value);
			return array_map(fn (mixed $item): mixed => $this->normalise(value: $item), $value);
		}

		$allScalar = true;
		foreach ($value as $item) {
			if (is_scalar($item) === false && $item !== null) {
				$allScalar = false;
				break;
			}
		}

		if ($allScalar === true) {
			sort($value);
			return $value;
		}

		return array_map(fn (mixed $item): mixed => $this->normalise(value: $item), $value);
	}//end normalise()

	/**
	 * Whether two parts hold the same thing.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b The other.
	 *
	 * @return bool True when they are the same.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function same(mixed $a, mixed $b): bool {
		return ($this->normalise(value: $a) === $this->normalise(value: $b));
	}//end same()

	/**
	 * Whether an array is a list rather than a map.
	 *
	 * @param array<mixed> $value The array.
	 *
	 * @return bool True when it is a list.
	 */
	private function isList(array $value): bool {
		return array_is_list($value);
	}//end isList()
}//end class
