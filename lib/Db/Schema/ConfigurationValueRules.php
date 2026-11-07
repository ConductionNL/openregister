<?php

/**
 * ConfigurationValueRules: the value rules of single schema configuration keys.
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
use OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration;

/**
 * The value rules for the plain configuration keys: strings, booleans, tags,
 * the calendar provider, the mail template and the draft lifetime.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class ConfigurationValueRules {
	/**
	 * Validate the `draftLifetimeDays` configuration value.
	 *
	 * A whole number of days, one or more, or `false` to keep the schema's
	 * drafts. Zero, a negative number, a fraction, `true` or text is refused:
	 * zero in particular must never read as "remove every draft now", and a
	 * schema that wants to keep its drafts says `false`.
	 *
	 * @param mixed $value The raw value, never null.
	 *
	 * @throws \InvalidArgumentException If the value is neither a whole number of days, one or more, nor `false`.
	 *
	 * @return int|false The days, or false when the schema keeps its drafts.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-lifetime-must-default-to-thirty-days-and-a-schema-must-be-able-to-change-it-or-opt-out
	 */
	public function validateDraftLifetimeValue(mixed $value): int|false {
		if ($value === false) {
			return false;
		}

		$value = $this->wholeDaysFromText(value: $value);
		if (is_int($value) === false || $value < 1) {
			throw new InvalidArgumentException('draftLifetimeDays must be a whole number of days, 1 or more, or false to keep drafts');
		}

		return $value;
	}//end validateDraftLifetimeValue()

	/**
	 * A text of digits without a leading zero read as its number; any other value as it is.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return mixed
	 */
	private function wholeDaysFromText(mixed $value): mixed {
		if (is_string($value) === true && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
			return (int)$value;
		}

		return $value;
	}//end wholeDaysFromText()

	/**
	 * Validate calendar provider configuration
	 *
	 * When calendarProvider.enabled is true, dtstart and titleTemplate are required.
	 * Warns (but does not reject) if referenced property names don't exist in schema properties.
	 *
	 * The `dates` block is validated whether or not the provider is enabled: a
	 * date kind that is stored unchecked is a kind the feed refuses to read
	 * later, at a moment nobody is looking at the schema editor. Every refusal
	 * names the property.
	 *
	 * @param array $config The calendarProvider config array
	 *
	 * @throws InvalidArgumentException If required fields are missing when enabled,
	 *                                  or a declared date kind is unusable
	 *
	 * @return void
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 *
	 * ObjectDateDeclaration::allFromConfig is a named constructor reading a config block; there is no instance to inject.
	 */
	public function validateCalendarProviderConfig(array $config): void {
		// Declared date kinds are refused on save, enabled or not.
		ObjectDateDeclaration::allFromConfig(calendarConfig: $config);

		// Only validate required fields when enabled.
		if (empty($config['enabled']) === true) {
			return;
		}

		foreach (['dtstart', 'titleTemplate'] as $field) {
			if (empty($config[$field]) === true) {
				throw new InvalidArgumentException('calendarProvider.'.$field.' is required when calendar provider is enabled');
			}
		}
	}//end validateCalendarProviderConfig()

	/**
	 * Validate a string configuration value
	 *
	 * @param string $key Configuration key
	 * @param mixed $value Configuration value
	 *
	 * @throws \InvalidArgumentException If validation fails
	 *
	 * @return string|null Validated value
	 */
	public function validateStringConfigValue(string $key, mixed $value): ?string {
		if ($value === '') {
			return null;
		}

		if ($value !== null && is_string($value) === false) {
			throw new InvalidArgumentException("Configuration '{$key}' must be a string or null");
		}

		return $value;
	}//end validateStringConfigValue()

	/**
	 * Validate a boolean configuration value
	 *
	 * @param string $key Configuration key
	 * @param mixed $value Configuration value
	 *
	 * @throws \InvalidArgumentException If validation fails
	 *
	 * @return void
	 */
	public function validateBoolConfigValue(string $key, mixed $value): void {
		if ($value !== null && is_bool($value) === false) {
			throw new InvalidArgumentException("Configuration '{$key}' must be a boolean or null");
		}
	}//end validateBoolConfigValue()

	/**
	 * Validate the allowedTags configuration value
	 *
	 * @param mixed $value Configuration value
	 *
	 * @throws \InvalidArgumentException If validation fails
	 *
	 * @return void
	 */
	public function validateAllowedTagsValue(mixed $value): void {
		if ($this->isAbsentConfig(value: $value, refusal: "Configuration 'allowedTags' must be an array or null") === true) {
			return;
		}

		foreach ($value as $tag) {
			if (is_string($tag) === false) {
				throw new InvalidArgumentException("All values in 'allowedTags' must be strings");
			}
		}
	}//end validateAllowedTagsValue()

	/**
	 * Whether an optional configuration value is absent (null); a value that is neither null nor an array is refused.
	 *
	 * @param mixed  $value   The configured value.
	 * @param string $refusal The message to refuse a value that is no array with.
	 *
	 * @throws \InvalidArgumentException If the value is neither null nor an array.
	 *
	 * @return bool True for null, false for an array.
	 */
	public function isAbsentConfig(mixed $value, string $refusal): bool {
		if ($value === null) {
			return true;
		}

		if (is_array($value) === false) {
			throw new InvalidArgumentException($refusal);
		}

		return false;
	}//end isAbsentConfig()

	/**
	 * Validate the mailObjectTemplate configuration value
	 *
	 * The template is a flat map of schema property name => prefill value
	 * used by the Mail-sidebar "create from email" action. String values
	 * may contain {{placeholder}} tokens; scalar non-string values pass
	 * through verbatim.
	 *
	 * @param mixed $value The mailObjectTemplate value to validate
	 *
	 * @throws InvalidArgumentException If the template is not a flat map of scalar values
	 *
	 * @return void
	 */
	public function validateMailObjectTemplateValue(mixed $value): void {
		if ($this->isAbsentConfig(value: $value, refusal: "Configuration 'mailObjectTemplate' must be an object or null") === true) {
			return;
		}

		foreach ($value as $field => $template) {
			$this->assertMailTemplateField(field: $field, template: $template);
		}
	}//end validateMailObjectTemplateValue()

	/**
	 * Refuse a mail-template field that is no property name, or whose value is no scalar.
	 *
	 * @param int|string $field    The template's key.
	 * @param mixed      $template The template's value.
	 *
	 * @throws InvalidArgumentException If the field or its value is not valid.
	 *
	 * @return void
	 */
	private function assertMailTemplateField(int|string $field, mixed $template): void {
		if (is_string($field) === false || $field === '') {
			throw new InvalidArgumentException("All keys in 'mailObjectTemplate' must be non-empty property names");
		}

		if (is_scalar($template) === false) {
			throw new InvalidArgumentException("Value for '$field' in 'mailObjectTemplate' must be a scalar");
		}
	}//end assertMailTemplateField()
}//end class
