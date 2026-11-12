<?php

/**
 * Validates the `transform` block of a migration-pack field mapping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\MigrationPack
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\MigrationPack;

use OCP\IL10N;

/**
 * Per-type checks for one transform: its type is allow-listed and the
 * fields that type requires are present. Each finding is translated; the
 * property names and values inside it stay as written.
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */
class PackTransformValidator {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n The app's translations.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Validate one `transform` block.
	 *
	 * @param mixed  $transform The transform value to validate.
	 * @param string $path      The error-message path prefix.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
	 */
	public function validate(mixed $transform, string $path): array {
		if (is_array($transform) === false) {
			return [$this->l10n->t('%s must be an object with a "type" key', [$path])];
		}

		$type = $transform['type'] ?? null;
		if (in_array($type, PackDefinitionValidator::ALLOWED_TRANSFORM_TYPES, true) === false) {
			return [$this->unknownType(path: $path, type: $type)];
		}

		return $this->validateTransformFields(transform: $transform, path: $path);
	}//end validateTransform()

	/**
	 * The finding for a transform type outside the allow-list.
	 *
	 * @param string $path The error-message path prefix.
	 * @param mixed  $type The type found.
	 *
	 * @return string The translated finding.
	 */
	private function unknownType(string $path, mixed $type): string {
		$allowed = implode(', ', PackDefinitionValidator::ALLOWED_TRANSFORM_TYPES);
		return $this->l10n->t('%1$s must be one of: %2$s (got %3$s)', [$path . '.type', $allowed, var_export($type, true)]);
	}//end unknownType()

	/**
	 * Validate the fields a transform's type requires; `trim` requires none.
	 *
	 * @param array<string, mixed> $transform The transform, of an allowed type.
	 * @param string               $path      The error-message path prefix.
	 *
	 * @return string[]
	 */
	private function validateTransformFields(array $transform, string $path): array {
		if ($transform['type'] === 'date') {
			return $this->validateDateTransform(transform: $transform, path: $path);
		}

		if ($transform['type'] === 'concat') {
			return $this->validateConcatTransform(transform: $transform, path: $path);
		}

		if ($transform['type'] === 'const' && array_key_exists('value', $transform) === false) {
			return [$this->l10n->t('%1$s is required for a %2$s transform', [$path . '.value', 'const'])];
		}

		return $this->validateMapTransform(transform: $transform, path: $path);
	}//end validateTransformFields()

	/**
	 * Validate a `date` transform: both formats are strings when present.
	 *
	 * @param array<string, mixed> $transform The transform.
	 * @param string               $path      The error-message path prefix.
	 *
	 * @return string[] At most the first finding.
	 */
	private function validateDateTransform(array $transform, string $path): array {
		foreach (['sourceFormat', 'targetFormat'] as $key) {
			if (isset($transform[$key]) === true && is_string($transform[$key]) === false) {
				return [$this->l10n->t('%s must be a string when present', [$path . '.' . $key])];
			}
		}

		return [];
	}//end validateDateTransform()

	/**
	 * Validate a `concat` transform: a non-empty list of non-empty strings.
	 *
	 * @param array<string, mixed> $transform The transform.
	 * @param string               $path      The error-message path prefix.
	 *
	 * @return string[] At most the first finding.
	 */
	private function validateConcatTransform(array $transform, string $path): array {
		if ($this->isNonEmptyArray(value: $transform['fields'] ?? null) === false) {
			return [$this->l10n->t('%1$s is required and must be a non-empty array for a %2$s transform', [$path . '.fields', 'concat'])];
		}

		foreach ($transform['fields'] as $fieldIndex => $field) {
			if ($this->isNonEmptyString(value: $field) === false) {
				return [$this->l10n->t('%s must be a non-empty string', [$path . '.fields[' . $fieldIndex . ']'])];
			}
		}

		return [];
	}//end validateConcatTransform()

	/**
	 * Validate a `bool-map` or `lookup` transform: a non-empty `map` object.
	 * Any other type passes.
	 *
	 * @param array<string, mixed> $transform The transform.
	 * @param string               $path      The error-message path prefix.
	 *
	 * @return string[]
	 */
	private function validateMapTransform(array $transform, string $path): array {
		$type = $transform['type'];
		if (in_array($type, ['bool-map', 'lookup'], true) === false) {
			return [];
		}

		if ($this->isNonEmptyArray(value: $transform['map'] ?? null) === false) {
			return [$this->l10n->t('%1$s is required and must be a non-empty object for a %2$s transform', [$path . '.map', $type])];
		}

		return [];
	}//end validateMapTransform()

	/**
	 * Whether a value is an array with at least one entry.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a non-empty array.
	 */
	private function isNonEmptyArray(mixed $value): bool {
		return is_array($value) === true && empty($value) === false;
	}//end isNonEmptyArray()

	/**
	 * Whether a value is a string other than ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a non-empty string.
	 */
	private function isNonEmptyString(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isNonEmptyString()
}//end class
