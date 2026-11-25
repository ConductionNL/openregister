<?php

/**
 * OpenRegister ErrorMessagesDeclaration
 *
 * Checks a property's `x-error-messages` at schema save.
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
 * @spec openspec/changes/modelling-validation-messages/specs/schema-validation-messages/spec.md#requirement-a-property-may-declare-its-own-validation-messages
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use Exception;

/**
 * Refuses an `x-error-messages` map the validator could never use.
 *
 * Each key is a validation keyword the object validator can fail on, and each
 * message is a non-empty string or a map from BCP 47 language tags to
 * non-empty strings. A message under a keyword nothing can fail on would never
 * be shown, and saving it would look like it works; so the save refuses it,
 * naming the property and the key.
 *
 * @spec openspec/changes/modelling-validation-messages/specs/schema-validation-messages/spec.md#requirement-a-property-may-declare-its-own-validation-messages
 */
final class ErrorMessagesDeclaration {

	/**
	 * The property key that holds the messages.
	 */
	public const KEY = 'x-error-messages';

	/**
	 * The keywords a declared message may answer.
	 *
	 * @var array<int, string>
	 */
	public const KEYWORDS = [
		'required',
		'type',
		'enum',
		'const',
		'pattern',
		'format',
		'minimum',
		'maximum',
		'exclusiveMinimum',
		'exclusiveMaximum',
		'multipleOf',
		'minLength',
		'maxLength',
		'minItems',
		'maxItems',
		'uniqueItems',
	];

	/**
	 * Refuse a property whose `x-error-messages` cannot be used.
	 *
	 * Static, like {@see CodedChoiceDeclaration::assert()}, so
	 * `PropertyValidatorHandler::validateProperty()` can call it without taking
	 * a dependency.
	 *
	 * @param array  $property The property definition.
	 * @param string $path     The property's path in the schema.
	 *
	 * @throws Exception Naming the property and the key, when a key or message does not hold.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/modelling-validation-messages/specs/schema-validation-messages/spec.md#requirement-a-property-may-declare-its-own-validation-messages
	 */
	public static function assert(array $property, string $path=''): void {
		if (array_key_exists(self::KEY, $property) === false) {
			return;
		}

		$messages = $property[self::KEY];
		if (is_array($messages) === false || ($messages !== [] && array_is_list($messages) === true)) {
			throw new Exception("'".self::KEY."' at '$path' must be an object keyed by validation keyword");
		}

		foreach ($messages as $keyword => $message) {
			if (in_array((string)$keyword, self::KEYWORDS, true) === false) {
				throw new Exception(
					"'".self::KEY."' at '$path' names '$keyword', which is not a validation keyword a message can answer. Use one of: "
					.implode(', ', self::KEYWORDS)
				);
			}

			self::assertMessage(message: $message, keyword: (string)$keyword, path: $path);
		}
	}//end assert()

	/**
	 * Refuse a message that is neither a non-empty string nor a language map of them.
	 *
	 * @param mixed  $message The declared message.
	 * @param string $keyword The keyword it answers.
	 * @param string $path    The property's path in the schema.
	 *
	 * @throws Exception Naming the property and the key.
	 *
	 * @return void
	 */
	private static function assertMessage(mixed $message, string $keyword, string $path): void {
		if (is_string($message) === true && trim($message) !== '') {
			return;
		}

		if (is_array($message) === false || $message === [] || array_is_list($message) === true) {
			throw new Exception(
				"'".self::KEY.".$keyword' at '$path' must be a non-empty string or a map from language tags to strings"
			);
		}

		foreach ($message as $language => $text) {
			self::assertTranslation(language: (string)$language, text: $text, where: self::KEY.".$keyword' at '$path'");
		}
	}//end assertMessage()

	/**
	 * Refuse a translation whose tag is not BCP 47 or whose text is empty.
	 *
	 * @param string $language The language tag.
	 * @param mixed  $text     The text.
	 * @param string $where    The key and path, for the refusal.
	 *
	 * @throws Exception Naming the property and the key.
	 *
	 * @return void
	 */
	private static function assertTranslation(string $language, mixed $text, string $where): void {
		if (preg_match(PropertyValidatorHandler::LANGUAGE_TAG_PATTERN, $language) !== 1) {
			throw new Exception("'$where uses '$language', which is not a valid BCP-47 language tag");
		}

		if (is_string($text) === false || trim($text) === '') {
			throw new Exception("'$where must have a non-empty string for '$language'");
		}
	}//end assertTranslation()
}//end class
