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
	 * The allow-listed fields, as named in a "must be one of" finding, and their allowed values.
	 *
	 * @var array<string, string[]>
	 */
	private const ALLOW_LISTS = [
		'"sourceFormat"' => self::ALLOWED_SOURCE_FORMATS,
		'idStrategy.type' => self::ALLOWED_ID_STRATEGY_TYPES,
	];

	/**
	 * Validator for the `transform` block of a field mapping.
	 *
	 * @var PackTransformValidator
	 */
	private readonly PackTransformValidator $transforms;

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
		$this->transforms = new PackTransformValidator(l10n: $l10n);
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
		if ($this->isNonEmptyString(value: $id) === false) {
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
		if ($this->isNonEmptyString(value: $definition['name'] ?? null) === false) {
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
		if (in_array($format, self::ALLOWED_SOURCE_FORMATS, true) === false) {
			return [$this->notOneOf(label: '"sourceFormat"', got: $format)];
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
	 */
	private function validateFieldMappings(array $definition): array {
		$mappings = $definition['fieldMappings'] ?? null;
		if ($this->isNonEmptyArray(value: $mappings) === false) {
			return [$this->l10n->t('"fieldMappings" is required and must be a non-empty array')];
		}

		$errors = [];
		foreach (array_values($mappings) as $position => $mapping) {
			$errors = array_merge($errors, $this->validateMapping(mapping: $mapping, number: ($position + 1)));
		}

		return $errors;
	}//end validateFieldMappings()

	/**
	 * Validate one field mapping.
	 *
	 * @param mixed $mapping The mapping entry.
	 * @param int   $number  Its position counted from 1.
	 *
	 * @return string[]
	 */
	private function validateMapping(mixed $mapping, int $number): array {
		if (is_array($mapping) === false) {
			return [$this->l10n->t('Mapping row %s must be an object', [$number])];
		}

		return array_merge(
			$this->validateMappingStrings(mapping: $mapping, number: $number),
			$this->validateMappingRequired(mapping: $mapping, number: $number),
			$this->validateMappingTransform(mapping: $mapping, number: $number)
		);
	}//end validateMapping()

	/**
	 * Validate the required non-empty string fields of a mapping, `source` then `target`.
	 *
	 * @param array<string, mixed> $mapping The mapping entry.
	 * @param int                  $number  The mapping's position counted from 1.
	 *
	 * @return string[]
	 */
	private function validateMappingStrings(array $mapping, int $number): array {
		$errors = [];
		foreach (['source', 'target'] as $key) {
			if ($this->isNonEmptyString(value: $mapping[$key] ?? null) === false) {
				$errors[] = $this->l10n->t('Mapping row %1$s: %2$s is required and must be a non-empty string', [$number, $key]);
			}
		}

		return $errors;
	}//end validateMappingStrings()

	/**
	 * Validate a mapping's optional `required` flag.
	 *
	 * @param array<string, mixed> $mapping The mapping entry.
	 * @param int                  $number  The mapping's position counted from 1.
	 *
	 * @return string[]
	 */
	private function validateMappingRequired(array $mapping, int $number): array {
		if (isset($mapping['required']) === true && is_bool($mapping['required']) === false) {
			return [$this->l10n->t('Mapping row %s: required must be a boolean when present', [$number])];
		}

		return [];
	}//end validateMappingRequired()

	/**
	 * Validate a mapping's optional `transform` block.
	 *
	 * @param array<string, mixed> $mapping The mapping entry.
	 * @param int                  $number  The mapping's position counted from 1.
	 *
	 * @return string[]
	 */
	private function validateMappingTransform(array $mapping, int $number): array {
		if (isset($mapping['transform']) === false) {
			return [];
		}

		return $this->transforms->validate(transform: $mapping['transform'], path: $this->l10n->t('Mapping row %s: transform', [$number]));
	}//end validateMappingTransform()

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

		if ($this->allPositiveIntegers(values: $definition['skipRows']) === false) {
			return [$this->l10n->t('"skipRows" entries must be positive integers')];
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
		if (in_array($type, self::ALLOWED_ID_STRATEGY_TYPES, true) === false) {
			return [$this->notOneOf(label: 'idStrategy.type', got: $type)];
		}

		return $this->validateIdStrategyField(idStrategy: $idStrategy);
	}//end validateIdStrategy()

	/**
	 * A `sourceField` id strategy names a non-empty source field.
	 *
	 * @param array<string, mixed> $idStrategy The id strategy, of an allowed type.
	 *
	 * @return string[]
	 */
	private function validateIdStrategyField(array $idStrategy): array {
		if ($idStrategy['type'] === 'sourceField' && $this->isNonEmptyString(value: $idStrategy['field'] ?? null) === false) {
			return [$this->l10n->t('idStrategy.field is required and must be a non-empty string when idStrategy.type is "sourceField"')];
		}

		return [];
	}//end validateIdStrategyField()

	/**
	 * The "must be one of" finding shared by every allow-listed field.
	 *
	 * The allowed values come from {@see self::ALLOW_LISTS} under the same label.
	 *
	 * @param string $label The field as named in the message; a key of ALLOW_LISTS.
	 * @param mixed  $got   The value found.
	 *
	 * @return string The translated finding.
	 */
	private function notOneOf(string $label, mixed $got): string {
		return $this->l10n->t(
			'%1$s must be one of: %2$s (got %3$s)',
			[$label, implode(', ', self::ALLOW_LISTS[$label]), var_export($got, true)]
		);
	}//end notOneOf()

	/**
	 * Whether every value is an integer of at least 1.
	 *
	 * @param array<mixed> $values The values.
	 *
	 * @return bool True when all are positive integers.
	 */
	private function allPositiveIntegers(array $values): bool {
		foreach ($values as $value) {
			if (is_int($value) === false || $value < 1) {
				return false;
			}
		}

		return true;
	}//end allPositiveIntegers()

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
