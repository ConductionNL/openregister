<?php

/**
 * WriteOnlyPathsDeclaration: the x-openregister-writeonly-paths annotation value.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Schema;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;

/**
 * Validates the nested write-only paths a schema declares; fails closed.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class WriteOnlyPathsDeclaration {
	/**
	 * Validate the `x-openregister-writeonly-paths` annotation value.
	 *
	 * Accepts a list of dot-separated paths rooted at a declared top-level
	 * property. Everything here throws rather than warns, because the caller
	 * re-throws for this key (see ConfigurationValidator): a declaration
	 * that does not parse must never persist as an unenforced no-op.
	 *
	 * Rejects, in order: a non-list value; a non-string / empty entry; a path
	 * with an empty segment (`a..b`, leading/trailing dot); and a path whose
	 * ROOT segment is not a property this schema declares — the last one is
	 * what turns a real-world typo into a 400 instead of a silent leak.
	 *
	 * A path is NOT required to resolve beyond its root, and deliberately so:
	 * the whole point is to name keys inside an untyped `object` property that
	 * the schema cannot describe, so there is nothing to validate them against.
	 *
	 * @param mixed      $value      The raw annotation value.
	 * @param array|null $properties The schema's declared properties.
	 *
	 * @throws \InvalidArgumentException If the declaration is malformed.
	 *
	 * @return array<int, string> The validated list of paths.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-nested-writeonly-paths-must-never-be-returned-on-any-read
	 */
	public function validateWriteOnlyPathsValue(mixed $value, ?array $properties): array {
		if (is_array($value) === false || array_is_list($value) === false) {
			throw new InvalidArgumentException(
				Schema::WRITEONLY_PATHS_ANNOTATION . ' must be a list of dot-separated path strings'
			);
		}

		$declared = $this->declaredNames(properties: $properties);
		$validated = [];
		foreach ($value as $path) {
			$validated[] = $this->validatedPath(path: $path, declared: $declared);
		}

		return array_values(array_unique($validated));
	}//end validateWriteOnlyPathsValue()

	/**
	 * The names of the declared properties; [] when there are none.
	 *
	 * @param array|null $properties The schema's declared properties.
	 *
	 * @return array<int, string>
	 */
	private function declaredNames(?array $properties): array {
		if (is_array($properties) === false) {
			return [];
		}

		return array_map('strval', array_keys($properties));
	}//end declaredNames()

	/**
	 * One declared path, refused when it is no string, has an empty segment or an undeclared root.
	 *
	 * @param mixed              $path     The declared path.
	 * @param array<int, string> $declared The names of the schema's declared properties.
	 *
	 * @throws \InvalidArgumentException If the path is malformed.
	 *
	 * @return string The path.
	 */
	private function validatedPath(mixed $path, array $declared): string {
		if (is_string($path) === false || trim($path) === '') {
			throw new InvalidArgumentException(
				Schema::WRITEONLY_PATHS_ANNOTATION . ' entries must be non-empty strings'
			);
		}

		$segments = explode('.', $path);
		$this->assertNoEmptySegment(path: $path, segments: $segments);
		$this->assertRootIsDeclared(path: $path, root: $segments[0], declared: $declared);

		return $path;
	}//end validatedPath()

	/**
	 * Refuse a path with an empty segment (`a..b`, a leading or trailing dot).
	 *
	 * @param string             $path     The declared path.
	 * @param array<int, string> $segments Its dot-separated segments.
	 *
	 * @throws \InvalidArgumentException If a segment is empty.
	 *
	 * @return void
	 */
	private function assertNoEmptySegment(string $path, array $segments): void {
		foreach ($segments as $segment) {
			if (trim($segment) === '') {
				throw new InvalidArgumentException(
					Schema::WRITEONLY_PATHS_ANNOTATION . ' path "' . $path . '" has an empty segment'
				);
			}
		}
	}//end assertNoEmptySegment()

	/**
	 * Refuse a path whose root segment is not a declared property.
	 *
	 * Root-segment existence check. Skipped when the schema declares no
	 * properties at all — an entity built in isolation (unit tests,
	 * partial hydration) has nothing to check against, and failing there
	 * would reject valid declarations for the wrong reason.
	 *
	 * @param string             $path     The declared path.
	 * @param string             $root     Its first segment.
	 * @param array<int, string> $declared The names of the schema's declared properties.
	 *
	 * @throws \InvalidArgumentException If the root is not declared.
	 *
	 * @return void
	 */
	private function assertRootIsDeclared(string $path, string $root, array $declared): void {
		$rootIsDeclared = (empty($declared) === true || in_array($root, $declared, true) === true);
		if ($rootIsDeclared === false) {
			throw new InvalidArgumentException(
				Schema::WRITEONLY_PATHS_ANNOTATION . ' path "' . $path . '" is rooted at "' . $root
				. '", which is not a declared property of this schema'
			);
		}
	}//end assertRootIsDeclared()
}//end class
