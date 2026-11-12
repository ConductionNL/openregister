<?php

/**
 * OpenRegister Schema Import — ImportOptions value object.
 *
 * Immutable options controlling a standards import: an optional property
 * subset, whether ancestor (inherited) properties are included, and an
 * optional target register. Pure data — no Nextcloud dependencies — so the
 * importers and their unit tests can construct it freely.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\Service\SchemaImport
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

namespace OCA\OpenRegister\Service\SchemaImport;

/**
 * Options for a standards import.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
final class ImportOptions {
	/**
	 * Constructor.
	 *
	 * @param array<int, string> $propertySubset Explicit property names to import; empty = all direct properties.
	 * @param bool $includeAncestors When true, inherited (ancestor) properties are also imported.
	 * @param int|null $targetRegister Register id the resulting schema should be associated with, or null.
	 */
	public function __construct(
		public readonly array $propertySubset = [],
		public readonly bool $includeAncestors = false,
		public readonly ?int $targetRegister = null,
	) {
	}//end __construct()

	/**
	 * Build options from a loosely-typed request payload.
	 *
	 * @param array<string, mixed> $payload The request body / parameters.
	 *
	 * @return self The constructed options.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public static function fromArray(array $payload): self {
		return new self(
			propertySubset: self::subsetFrom(payload: $payload),
			includeAncestors: self::includeAncestorsFrom(payload: $payload),
			targetRegister: self::targetRegisterFrom(payload: $payload)
		);
	}//end fromArray()

	/**
	 * The non-empty string property names of the payload's `propertySubset`.
	 *
	 * @param array<string, mixed> $payload The request body / parameters.
	 *
	 * @return array<int, string> The requested property names, in order.
	 */
	private static function subsetFrom(array $payload): array {
		if (isset($payload['propertySubset']) === false || is_array($payload['propertySubset']) === false) {
			return [];
		}

		return self::nonEmptyStrings(values: $payload['propertySubset']);
	}//end subsetFrom()

	/**
	 * The non-empty string members of a list, in order and reindexed.
	 *
	 * @param array<int|string, mixed> $values The candidate values.
	 *
	 * @return array<int, string> The non-empty strings.
	 */
	private static function nonEmptyStrings(array $values): array {
		$strings = [];
		foreach ($values as $value) {
			if (is_string($value) === true && $value !== '') {
				$strings[] = $value;
			}
		}

		return $strings;
	}//end nonEmptyStrings()

	/**
	 * The payload's `includeAncestors` flag as a boolean (absent = false).
	 *
	 * @param array<string, mixed> $payload The request body / parameters.
	 *
	 * @return bool Whether ancestor properties are requested.
	 */
	private static function includeAncestorsFrom(array $payload): bool {
		if (isset($payload['includeAncestors']) === true) {
			return filter_var($payload['includeAncestors'], FILTER_VALIDATE_BOOLEAN);
		}

		return false;
	}//end includeAncestorsFrom()

	/**
	 * The payload's numeric `targetRegister` as an int, or null.
	 *
	 * @param array<string, mixed> $payload The request body / parameters.
	 *
	 * @return int|null The target register id.
	 */
	private static function targetRegisterFrom(array $payload): ?int {
		if (isset($payload['targetRegister']) === true && is_numeric($payload['targetRegister']) === true) {
			return (int)$payload['targetRegister'];
		}

		return null;
	}//end targetRegisterFrom()

	/**
	 * Whether an explicit property subset was requested.
	 *
	 * @return bool True when a non-empty subset is set.
	 */
	public function hasSubset(): bool {
		return $this->propertySubset !== [];
	}//end hasSubset()
}//end class
