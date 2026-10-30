<?php

/**
 * Validates a migration-pack definition document.
 *
 * A migration pack is a declarative JSON document describing how to map a
 * legacy/source export (CSV column, Excel column, or JSON pointer) onto an
 * OpenRegister schema's properties, so a municipality's legacy zaaksysteem
 * export can be imported without hand-mapping every column each time. This
 * class validates the STRUCTURE of that document only — it does not know
 * about a specific target register/schema (the same pack can be reused
 * against any schema whose property names match the `target` values), so it
 * cannot validate that `target` values exist on a particular schema. That
 * check happens implicitly at import time: an unmapped target is passed
 * through to the existing schema-driven import pipeline unchanged.
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

use OCA\OpenRegister\Exception\InvalidPackDefinitionException;
use OCP\IL10N;

/**
 * Structural + business-rule validator for a migration-pack JSON document.
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One small, independently-testable validate*() method
 *     per pack-document field keeps each check simple; the class total sums them, not any single method.
 */
class PackDefinitionValidator {
	/**
	 * Source formats a pack may declare.
	 *
	 * @var string[]
	 */
	public const ALLOWED_SOURCE_FORMATS = ['csv', 'json', 'excel'];

	/**
	 * Transform types a field mapping may declare.
	 *
	 * @var string[]
	 */
	public const ALLOWED_TRANSFORM_TYPES = ['trim', 'date', 'bool-map', 'concat', 'lookup', 'const'];

	/**
	 * IdStrategy types a pack may declare.
	 *
	 * @var string[]
	 */
	public const ALLOWED_ID_STRATEGY_TYPES = ['sourceField', 'generate'];

	/**
	 * Constructor.
	 *
	 * Every message is shown to the person editing the pack (the pack editor
	 * lists them under the save error), so each goes through the app's
	 * translations; the property names and values inside them stay as written.
	 *
	 * @param IL10N $l10n The app's translations.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Validate a pack definition document.
	 *
	 * @param array<string, mixed> $definition The decoded pack definition JSON.
	 *
	 * @return string[] List of validation error messages. Empty when valid.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
	 */
	public function validate(array $definition): array {
		$errors = [];

		$errors = array_merge($errors, $this->validateId(definition: $definition));
		$errors = array_merge($errors, $this->validateName(definition: $definition));
		$errors = array_merge($errors, $this->validateSourceFormat(definition: $definition));
		$errors = array_merge($errors, $this->validateVersion(definition: $definition));
		$errors = array_merge($errors, $this->validateFieldMappings(definition: $definition));
		$errors = array_merge($errors, $this->validateDefaults(definition: $definition));
		$errors = array_merge($errors, $this->validateSkipRows(definition: $definition));
		$errors = array_merge($errors, $this->validateIdStrategy(definition: $definition));

		return $errors;
	}//end validate()

	/**
	 * Validate and throw on the first structural problem.
	 *
	 * @param array<string, mixed> $definition The decoded pack definition JSON.
	 *
	 * @return void
	 *
	 * @throws InvalidPackDefinitionException When the definition is invalid. It carries every error found.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
	 */
	public function assertValid(array $definition): void {
		$errors = $this->validate(definition: $definition);
		if (empty($errors) === false) {
			throw new InvalidPackDefinitionException(errors: $errors);
		}
	}//end assertValid()

	/**
	 * Validate the `id` field (pack slug, used as the lookup key).
	 *
	 * @param array<string, mixed> $definition The pack definition.
	 *
	 * @return string[]
	 */
	private function validateId(array $definition): array {
		$id = $definition['id'] ?? null;
		if (is_string($id) === false || $id === '') {
			return [$this->l10n->t('"%s" is required and must be a non-empty string', ['id'])];
		}

		if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) !== 1) {
			return [$this->l10n->t('"id" must be a lowercase slug (letters, digits, hyphens), got "%s"', [$id])];
		}

		return [];
	}//end validateId()

	/**
	 * Validate the `name` field.
	 *
	 * @param array<string, mixed> $definition The pack definition.
	 *
	 * @return string[]
	 */
	private function validateName(array $definition): array {
		$name = $definition['name'] ?? null;
		if (is_string($name) === false || $name === '') {
			return [$this->l10n->t('"%s" is required and must be a non-empty string', ['name'])];
		}

		return [];
	}//end validateName()

	/**
	 * Validate the `sourceFormat` field.
	 *
	 * @param array<string, mixed> $definition The pack definition.
	 *
	 * @return string[]
	 */
	private function validateSourceFormat(array $definition): array {
		$format = $definition['sourceFormat'] ?? null;
		if (is_string($format) === false || in_array($format, self::ALLOWED_SOURCE_FORMATS, true) === false) {
			return [
				$this->l10n->t(
					'%1$s must be one of: %2$s (got %3$s)',
					['"sourceFormat"', implode(', ', self::ALLOWED_SOURCE_FORMATS), var_export($format, true)]
				),
			];
		}

		return [];
	}//end validateSourceFormat()

	/**
	 * Validate the `version` field (strict semver: MAJOR.MINOR.PATCH).
	 *
	 * @param array<string, mixed> $definition The pack definition.
	 *
	 * @return string[]
	 */
	private function validateVersion(array $definition): array {
		$version = $definition['version'] ?? null;
		if (is_string($version) === false || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
			return [$this->l10n->t('"version" must be a semver string (MAJOR.MINOR.PATCH), got %s', [var_export($version, true)])];
		}

		return [];
	}//end validateVersion()

	/**
	 * Validate the `fieldMappings` array and every entry within it.
	 *
	 * Errors name a mapping by its position counted from 1 ("Mapping row 4"),
	 * the number the pack editor shows in its table, not the 0-based array
	 * index.
	 *
	 * @param array<string, mixed> $definition The pack definition.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Per-transform-type validation requires many branches.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Per-transform-type validation requires many branches.
	 */
	private function validateFieldMappings(array $definition): array {
		$mappings = $definition['fieldMappings'] ?? null;
		if (is_array($mappings) === false || empty($mappings) === true) {
			return [$this->l10n->t('"fieldMappings" is required and must be a non-empty array')];
		}

		$errors = [];
		foreach (array_values($mappings) as $position => $mapping) {
			$number = ($position + 1);
			if (is_array($mapping) === false) {
				$errors[] = $this->l10n->t('Mapping row %s must be an object', [$number]);
				continue;
			}

			$source = $mapping['source'] ?? null;
			if (is_string($source) === false || $source === '') {
				$errors[] = $this->l10n->t('Mapping row %1$s: %2$s is required and must be a non-empty string', [$number, 'source']);
			}

			$target = $mapping['target'] ?? null;
			if (is_string($target) === false || $target === '') {
				$errors[] = $this->l10n->t('Mapping row %1$s: %2$s is required and must be a non-empty string', [$number, 'target']);
			}

			if (isset($mapping['required']) === true && is_bool($mapping['required']) === false) {
				$errors[] = $this->l10n->t('Mapping row %s: required must be a boolean when present', [$number]);
			}

			if (isset($mapping['transform']) === true) {
				$errors = array_merge(
					$errors,
					$this->validateTransform(transform: $mapping['transform'], path: $this->l10n->t('Mapping row %s: transform', [$number]))
				);
			}
		}//end foreach

		return $errors;
	}//end validateFieldMappings()

	/**
	 * Validate one `transform` block.
	 *
	 * @param mixed $transform The transform value to validate.
	 * @param string $path The error-message path prefix.
	 *
	 * @return string[]
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each transform type has its own required-field shape.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Each transform type has its own required-field shape.
	 */
	private function validateTransform($transform, string $path): array {
		if (is_array($transform) === false) {
			return [$this->l10n->t('%s must be an object with a "type" key', [$path])];
		}

		$type = $transform['type'] ?? null;
		if (is_string($type) === false || in_array($type, self::ALLOWED_TRANSFORM_TYPES, true) === false) {
			return [
				$this->l10n->t(
					'%1$s must be one of: %2$s (got %3$s)',
					[$path . '.type', implode(', ', self::ALLOWED_TRANSFORM_TYPES), var_export($type, true)]
				),
			];
		}

		switch ($type) {
			case 'date':
				if (isset($transform['sourceFormat']) === true && is_string($transform['sourceFormat']) === false) {
					return [$this->l10n->t('%s must be a string when present', [$path . '.sourceFormat'])];
				}

				if (isset($transform['targetFormat']) === true && is_string($transform['targetFormat']) === false) {
					return [$this->l10n->t('%s must be a string when present', [$path . '.targetFormat'])];
				}
				break;

			case 'bool-map':
				if (is_array($transform['map'] ?? null) === false || empty($transform['map']) === true) {
					return [$this->l10n->t('%1$s is required and must be a non-empty object for a %2$s transform', [$path . '.map', 'bool-map'])];
				}
				break;

			case 'lookup':
				if (is_array($transform['map'] ?? null) === false || empty($transform['map']) === true) {
					return [$this->l10n->t('%1$s is required and must be a non-empty object for a %2$s transform', [$path . '.map', 'lookup'])];
				}
				break;

			case 'concat':
				if (is_array($transform['fields'] ?? null) === false || empty($transform['fields']) === true) {
					return [$this->l10n->t('%1$s is required and must be a non-empty array for a %2$s transform', [$path . '.fields', 'concat'])];
				}

				foreach ($transform['fields'] as $fieldIndex => $field) {
					if (is_string($field) === false || $field === '') {
						return [$this->l10n->t('%s must be a non-empty string', [$path . '.fields[' . $fieldIndex . ']'])];
					}
				}
				break;

			case 'const':
				if (array_key_exists('value', $transform) === false) {
					return [$this->l10n->t('%1$s is required for a %2$s transform', [$path . '.value', 'const'])];
				}
				break;

			case 'trim':
			default:
				// No extra fields required.
				break;
		}//end switch

		return [];
	}//end validateTransform()

	/**
	 * Validate the optional `defaults` map.
	 *
	 * @param array<string, mixed> $definition The pack definition.
	 *
	 * @return string[]
	 */
	private function validateDefaults(array $definition): array {
		if (isset($definition['defaults']) === false) {
			return [];
		}

		if (is_array($definition['defaults']) === false) {
			return [$this->l10n->t('"defaults" must be an object of target-property => default value when present')];
		}

		return [];
	}//end validateDefaults()

	/**
	 * Validate the optional `skipRows` array.
	 *
	 * @param array<string, mixed> $definition The pack definition.
	 *
	 * @return string[]
	 */
	private function validateSkipRows(array $definition): array {
		if (isset($definition['skipRows']) === false) {
			return [];
		}

		if (is_array($definition['skipRows']) === false) {
			return [$this->l10n->t('"skipRows" must be an array of row numbers when present')];
		}

		foreach ($definition['skipRows'] as $row) {
			if (is_int($row) === false || $row < 1) {
				return [$this->l10n->t('"skipRows" entries must be positive integers')];
			}
		}

		return [];
	}//end validateSkipRows()

	/**
	 * Validate the required `idStrategy` block.
	 *
	 * @param array<string, mixed> $definition The pack definition.
	 *
	 * @return string[]
	 */
	private function validateIdStrategy(array $definition): array {
		$idStrategy = $definition['idStrategy'] ?? null;
		if (is_array($idStrategy) === false) {
			return [$this->l10n->t('"idStrategy" is required and must be an object with a "type" key')];
		}

		$type = $idStrategy['type'] ?? null;
		if (is_string($type) === false || in_array($type, self::ALLOWED_ID_STRATEGY_TYPES, true) === false) {
			return [
				$this->l10n->t(
					'%1$s must be one of: %2$s (got %3$s)',
					['idStrategy.type', implode(', ', self::ALLOWED_ID_STRATEGY_TYPES), var_export($type, true)]
				),
			];
		}

		if ($type === 'sourceField'
			&& (is_string($idStrategy['field'] ?? null) === false || $idStrategy['field'] === '')
		) {
			return [$this->l10n->t('idStrategy.field is required and must be a non-empty string when idStrategy.type is "sourceField"')];
		}

		return [];
	}//end validateIdStrategy()
}//end class
