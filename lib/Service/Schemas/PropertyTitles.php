<?php

/**
 * A property's name in several languages.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-field-names-per-language/specs/schema-property-exploration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Service\LanguageService;

/**
 * The `titles` modifier: `{ "<language tag>": "<name>" }` beside `title`.
 *
 * `title` stays the default name, so a reader that ignores `titles` keeps
 * working. A schema read that names a language answers each property's
 * `title` from `titles` in that language, then its base language, and keeps
 * the stored `title` otherwise; a read without a language changes nothing.
 *
 * @spec openspec/changes/modelling-field-names-per-language/specs/schema-property-exploration/spec.md
 */
final class PropertyTitles {

	/**
	 * The modifier key.
	 *
	 * @var string
	 */
	public const KEY = 'titles';

	/**
	 * Refuse a `titles` value that is not a map of language tags to non-empty names.
	 *
	 * @param array  $property The property definition.
	 * @param string $path     The property's path in the schema, for the message.
	 *
	 * @return void
	 *
	 * @throws PropertyVocabularyException When the map, a key or a name is not acceptable.
	 *
	 * @spec openspec/changes/modelling-field-names-per-language/specs/schema-property-exploration/spec.md
	 */
	public static function assert(array $property, string $path): void {
		// Null means "not set", as it does for the other modifiers.
		if (($property[self::KEY] ?? null) === null) {
			return;
		}

		$titles = $property[self::KEY];
		if (is_array($titles) === false || ($titles !== [] && array_is_list($titles) === true)) {
			self::refuse(key: self::KEY, path: $path, message: "'titles' at '$path' must be an object of language tags to names");
		}

		foreach ($titles as $language => $name) {
			$language = (string) $language;
			if (preg_match(PropertyValidatorHandler::LANGUAGE_TAG_PATTERN, $language) !== 1) {
				self::refuse(key: $language, path: $path, message: "'titles' at '$path' has '$language', which is not a language tag such as nl or pt-BR");
			}

			if (is_string($name) === false || trim($name) === '') {
				self::refuse(key: $language, path: $path, message: "'titles.$language' at '$path' must be a non-empty name");
			}
		}
	}//end assert()

	/**
	 * The language a schema read asks for: `_lang`, else the first `Accept-Language` range, else none.
	 *
	 * @param mixed $langParam      The `_lang` query parameter.
	 * @param mixed $acceptLanguage The `Accept-Language` header.
	 *
	 * @return string|null The language tag, or null when the read names no language.
	 *
	 * @spec openspec/changes/modelling-field-names-per-language/specs/schema-property-exploration/spec.md
	 */
	public static function requestedLanguage(mixed $langParam, mixed $acceptLanguage): ?string {
		if (is_string($langParam) === true && trim($langParam) !== '') {
			return trim($langParam);
		}

		if (is_string($acceptLanguage) === false || trim($acceptLanguage) === '') {
			return null;
		}

		return (LanguageService::parseAcceptLanguageHeader($acceptLanguage)[0] ?? null);
	}//end requestedLanguage()

	/**
	 * A serialised schema with each property's `title` in the language, where `titles` has it.
	 *
	 * Nested object properties and array items are projected too. `titles`
	 * itself is left in place.
	 *
	 * @param array  $schema   The serialised schema.
	 * @param string $language The requested language tag.
	 *
	 * @return array The schema.
	 *
	 * @spec openspec/changes/modelling-field-names-per-language/specs/schema-property-exploration/spec.md
	 */
	public static function projected(array $schema, string $language): array {
		if (is_array($schema['properties'] ?? null) === true) {
			$schema['properties'] = self::projectedProperties(properties: $schema['properties'], language: $language);
		}

		return $schema;
	}//end projected()

	/**
	 * The properties with their titles in the language.
	 *
	 * @param array  $properties The property definitions.
	 * @param string $language   The language tag.
	 *
	 * @return array The properties.
	 */
	private static function projectedProperties(array $properties, string $language): array {
		foreach ($properties as $name => $property) {
			if (is_array($property) === true) {
				$properties[$name] = self::projectedProperty(property: $property, language: $language);
			}
		}

		return $properties;
	}//end projectedProperties()

	/**
	 * One property with its title, and its nested properties' titles, in the language.
	 *
	 * @param array  $property The property definition.
	 * @param string $language The language tag.
	 *
	 * @return array The property.
	 */
	private static function projectedProperty(array $property, string $language): array {
		$title = self::titleIn(titles: ($property[self::KEY] ?? null), language: $language);
		if ($title !== null) {
			$property['title'] = $title;
		}

		if (is_array($property['properties'] ?? null) === true) {
			$property['properties'] = self::projectedProperties(properties: $property['properties'], language: $language);
		}

		if (is_array($property['items'] ?? null) === true && array_is_list($property['items']) === false) {
			$property['items'] = self::projectedProperty(property: $property['items'], language: $language);
		}

		return $property;
	}//end projectedProperty()

	/**
	 * The name for a language: the tag itself, then its base language; case does not matter.
	 *
	 * @param mixed  $titles   The `titles` map.
	 * @param string $language The language tag.
	 *
	 * @return string|null The name, or null when the map has neither.
	 */
	private static function titleIn(mixed $titles, string $language): ?string {
		if (is_array($titles) === false) {
			return null;
		}

		$byLanguage = array_change_key_case($titles, CASE_LOWER);
		$wanted = strtolower($language);
		foreach ([$wanted, explode('-', $wanted)[0]] as $tag) {
			if (is_string($byLanguage[$tag] ?? null) === true && $byLanguage[$tag] !== '') {
				return $byLanguage[$tag];
			}
		}

		return null;
	}//end titleIn()

	/**
	 * Throw the refusal for one key.
	 *
	 * @param string $key     The offending key.
	 * @param string $path    The property's path.
	 * @param string $message The sentence naming property and key.
	 *
	 * @return never
	 *
	 * @throws PropertyVocabularyException Always.
	 */
	private static function refuse(string $key, string $path, string $message): never {
		$error = ['code' => 'property-vocabulary-invalid-titles', 'key' => $key, 'path' => $path, 'message' => $message];
		throw new PropertyVocabularyException($message, [$error]);
	}//end refuse()
}//end class
