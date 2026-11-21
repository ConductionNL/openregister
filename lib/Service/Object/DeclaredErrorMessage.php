<?php

/**
 * OpenRegister DeclaredErrorMessage
 *
 * The message a schema property declares for the rule it failed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-validation-messages/specs/schema-validation-messages/spec.md#requirement-a-failed-rule-returns-the-declared-message-in-the-request-language
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Service\Schemas\ErrorMessagesDeclaration;
use Opis\JsonSchema\Errors\ValidationError;

/**
 * Looks up `x-error-messages` for a validation error, or answers null.
 *
 * The property definition is read off the error itself: Opis keeps the schema
 * that failed, so no second copy of the schema is needed. A failure inside an
 * object is reported as a `properties` (or `items`) error around the real
 * one, so the lookup follows the first nested error down to the rule that
 * failed. `required` is reported on the parent object; its message is the one
 * the MISSING property declares.
 *
 * Null means "nothing declared": the caller then builds today's message, so a
 * schema without `x-error-messages` answers exactly as before.
 *
 * @spec openspec/changes/modelling-validation-messages/specs/schema-validation-messages/spec.md#requirement-a-failed-rule-returns-the-declared-message-in-the-request-language
 */
final class DeclaredErrorMessage {

	/**
	 * The longest stretch of the submitted value a message carries.
	 */
	public const VALUE_LIMIT = 100;

	/**
	 * The language every message falls back to.
	 */
	public const FALLBACK_LANGUAGE = 'nl';

	/**
	 * Keywords that only wrap the error of a nested value.
	 *
	 * @var array<int, string>
	 */
	private const WRAPPERS = ['properties', 'items', 'prefixItems', 'additionalProperties', 'allOf', '$ref'];

	/**
	 * The error arguments that name a rule's limit, in the order they are read.
	 *
	 * @var array<int, string>
	 */
	private const LIMIT_ARGS = ['min', 'max', 'divisor', 'pattern', 'format', 'const', 'expected'];

	/**
	 * Build the lookup for one request.
	 *
	 * @param string|null $language The language the request resolved to, or null when none was.
	 */
	public function __construct(private readonly ?string $language=null) {
	}//end __construct()

	/**
	 * The declared message for the error, in the request language.
	 *
	 * @param ValidationError $error The error Opis reported.
	 *
	 * @return string|null The message, or null when the failing property declares none.
	 *
	 * @spec openspec/changes/modelling-validation-messages/specs/schema-validation-messages/spec.md#requirement-a-failed-rule-returns-the-declared-message-in-the-request-language
	 */
	public function forError(ValidationError $error): ?string {
		if (in_array($error->keyword(), self::WRAPPERS, true) === true) {
			$nested = $error->subErrors();
			if ($nested === []) {
				return null;
			}

			return $this->forError(error: $nested[0]);
		}

		if ($error->keyword() === 'required') {
			return $this->forMissing(error: $error);
		}

		$path     = $error->data()->fullPath();
		$property = 'root';
		if ($path !== []) {
			$property = (string)end($path);
		}
		$text = $this->declared(definition: $error->schema()->info()->data(), keyword: $error->keyword());
		if ($text === null) {
			return null;
		}

		return $this->substituted(
			text: $text,
			values: ['{value}' => self::shown(value: $error->data()->value()), '{property}' => $property, '{limit}' => $this->limit(args: $error->args())]
		);
	}//end forError()

	/**
	 * The declared `required` message of the first missing property that has one.
	 *
	 * @param ValidationError $error The `required` error, reported on the parent object.
	 *
	 * @return string|null The message, or null when no missing property declares one.
	 */
	private function forMissing(ValidationError $error): ?string {
		$parent = self::asArray(value: $error->schema()->info()->data());
		foreach ((array)($error->args()['missing'] ?? []) as $missing) {
			$text = $this->declared(definition: ($parent['properties'][$missing] ?? null), keyword: 'required');
			if ($text !== null) {
				return $this->substituted(text: $text, values: ['{value}' => '', '{property}' => (string)$missing, '{limit}' => '']);
			}
		}

		return null;
	}//end forMissing()

	/**
	 * The message a definition declares for a keyword, in the request language.
	 *
	 * Fallback order: the request language (then its primary subtag), `nl`, the
	 * first declared language.
	 *
	 * @param mixed  $definition The property definition (decoded JSON).
	 * @param string $keyword    The failed keyword.
	 *
	 * @return string|null The text, or null when none is declared.
	 */
	private function declared(mixed $definition, string $keyword): ?string {
		$message = (self::asArray(value: $definition)[ErrorMessagesDeclaration::KEY][$keyword] ?? null);
		if (is_string($message) === true) {
			return $message;
		}

		if (is_array($message) === false || $message === []) {
			return null;
		}

		foreach ($this->languages() as $language) {
			if (is_string($message[$language] ?? null) === true) {
				return $message[$language];
			}
		}

		$first = reset($message);
		if (is_string($first) === false) {
			return null;
		}

		return $first;
	}//end declared()

	/**
	 * The languages to try, most preferred first.
	 *
	 * @return array<int, string> The language tags.
	 */
	private function languages(): array {
		$languages = [];
		if ($this->language !== null && $this->language !== '') {
			$languages[] = $this->language;
			$languages[] = explode('-', $this->language)[0];
		}

		$languages[] = self::FALLBACK_LANGUAGE;

		return array_values(array_unique($languages));
	}//end languages()

	/**
	 * The text with its placeholders replaced, in one pass: a substituted value is never read again.
	 *
	 * @param string                $text   The declared text.
	 * @param array<string, string> $values The placeholder values.
	 *
	 * @return string The message.
	 */
	private function substituted(string $text, array $values): string {
		return strtr($text, $values);
	}//end substituted()

	/**
	 * The rule's limit as the error reports it, empty when it reports none.
	 *
	 * @param array<string, mixed> $args The error's arguments.
	 *
	 * @return string The limit.
	 */
	private function limit(array $args): string {
		foreach (self::LIMIT_ARGS as $name) {
			if (array_key_exists($name, $args) === true) {
				return self::shown(value: $args[$name]);
			}
		}

		return '';
	}//end limit()

	/**
	 * A value as plain text, cut to {@see self::VALUE_LIMIT} characters.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private static function shown(mixed $value): string {
		if (is_bool($value) === true) {
			$value = json_encode($value);
		}

		if (is_scalar($value) === false) {
			$value = (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		return mb_substr((string)$value, 0, self::VALUE_LIMIT);
	}//end shown()

	/**
	 * A decoded JSON value as an array, an empty one for anything that is not an object.
	 *
	 * @param mixed $value The value (stdClass, array or other).
	 *
	 * @return array<string, mixed> The array.
	 */
	private static function asArray(mixed $value): array {
		if (is_object($value) === true || is_array($value) === true) {
			$decoded = json_decode((string)json_encode($value), true);
			if (is_array($decoded) === true) {
				return $decoded;
			}
		}

		return [];
	}//end asArray()
}//end class
