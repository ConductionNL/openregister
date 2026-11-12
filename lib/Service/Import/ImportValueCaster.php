<?php

/**
 * Casts the text of an import cell to the type its schema property declares.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use DateTime;
use DateTimeZone;
use Exception;
use OCA\OpenRegister\Db\ObjectHandling;

/**
 * Casts import cell values by schema type.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
class ImportValueCaster {

	/**
	 * The `@self` properties that hold a datetime.
	 */
	private const SELF_DATETIMES = ['published', 'created', 'updated'];

	/**
	 * The texts that read as true.
	 */
	private const TRUE_TEXTS = ['true', '1', 'yes', 'on', 'enabled'];

	/**
	 * Cast the properties of an object by their schema definition.
	 *
	 * `@self` and properties the schema does not declare stay as they are.
	 *
	 * @param array<string, mixed> $objectData The object
	 * @param array<string, mixed> $properties The schema's property definitions
	 *
	 * @return array<string, mixed> The cast object
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function castObject(array $objectData, array $properties): array {
		$cast = $objectData;
		foreach ($objectData as $name => $value) {
			$definition = ($properties[$name] ?? null);
			if ($name !== '@self' && $definition !== null) {
				$cast[$name] = $this->cast(value: $value, propertyDef: $definition);
			}
		}

		return $cast;
	}//end castObject()

	/**
	 * Cast a value by its property definition; null and '' stay as they are.
	 *
	 * @param mixed                $value       The value
	 * @param array<string, mixed> $propertyDef The property definition
	 *
	 * @return mixed The cast value
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function cast(mixed $value, array $propertyDef): mixed {
		if ($value === null || $value === '') {
			return $value;
		}

		$type = ($propertyDef['type'] ?? 'string');
		if ($type === 'object') {
			return $this->objectValue(value: $value, propertyDef: $propertyDef);
		}

		return $this->byType(value: $value, type: $type);
	}//end cast()

	/**
	 * Cast a `@self` value: the datetime properties are normalised, the rest stays.
	 *
	 * @param string $propertyName The `@self` property
	 * @param string $value        The value
	 *
	 * @return string The cast value
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function castSelfProperty(string $propertyName, string $value): string {
		if (in_array($propertyName, self::SELF_DATETIMES, true) === true) {
			return $this->dateTime(value: $value);
		}

		return $value;
	}//end castSelfProperty()

	/**
	 * Normalise ISO 8601 and date-only text to `Y-m-d H:i:s`.
	 *
	 * An offset-bearing timestamp becomes UTC, so the instant survives dropping
	 * the offset. Text that cannot be parsed stays as it is.
	 *
	 * @param string $value The text
	 *
	 * @return string The datetime
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function dateTime(string $value): string {
		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === 1) {
			return $this->parsedDateTime(value: $value, zone: new DateTimeZone('UTC'));
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value) === 1) {
			return $this->parsedDateTime(value: $value, zone: null);
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			return $value . ' 00:00:00';
		}

		return $value;
	}//end dateTime()

	/**
	 * Format a timestamp, moved to a zone when one is given.
	 *
	 * @param string            $value The timestamp
	 * @param DateTimeZone|null $zone  The zone to move it to
	 *
	 * @return string The datetime, or the text when it cannot be parsed
	 */
	private function parsedDateTime(string $value, ?DateTimeZone $zone): string {
		try {
			$dateTime = new DateTime($value);
		} catch (Exception $e) {
			return $value;
		}

		if ($zone !== null) {
			$dateTime->setTimezone($zone);
		}

		return $dateTime->format(format: 'Y-m-d H:i:s');
	}//end parsedDateTime()

	/**
	 * Cast a filled value of any type but object.
	 *
	 * @param mixed $value The value
	 * @param mixed $type  The declared type
	 *
	 * @return mixed The cast value
	 */
	private function byType(mixed $value, mixed $type): mixed {
		switch ($type) {
			case 'integer':
				return (int)$value;
			case 'number':
				return (float)$value;
			case 'boolean':
				return $this->booleanValue(value: $value);
			case 'array':
				return $this->arrayValue(value: $value);
			default:
				return (string)$value;
		}
	}//end byType()

	/**
	 * Cast an object property: a relation keeps its UUID text.
	 *
	 * @param mixed                $value       The value
	 * @param array<string, mixed> $propertyDef The property definition
	 *
	 * @return mixed The cast value
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectHandling::relates() is the established enum-style helper idiom
	 */
	private function objectValue(mixed $value, array $propertyDef): mixed {
		$handling = ($propertyDef['objectConfiguration']['handling'] ?? null);
		if ($handling !== null && ObjectHandling::relates($handling) === true) {
			return (string)$value;
		}

		if (is_array($value) === true || is_object($value) === true) {
			return $value;
		}

		$text = trim((string)$value);

		return ($this->decoded(text: $text, brackets: '{}') ?? ['value' => $text]);
	}//end objectValue()

	/**
	 * Read a value as a boolean.
	 *
	 * @param mixed $value The value
	 *
	 * @return bool The boolean
	 */
	private function booleanValue(mixed $value): bool {
		return in_array(strtolower(trim((string)$value)), self::TRUE_TEXTS);
	}//end booleanValue()

	/**
	 * Read a value as a list: a JSON array, comma-separated text or one element.
	 *
	 * @param mixed $value The value
	 *
	 * @return array<int|string, mixed> The list
	 */
	private function arrayValue(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_string($value) === false) {
			return [$value];
		}

		return $this->listOfText(text: trim($value));
	}//end arrayValue()

	/**
	 * Read trimmed text as a list.
	 *
	 * @param string $text The text
	 *
	 * @return array<int|string, mixed> The list
	 */
	private function listOfText(string $text): array {
		if ($text === '') {
			return [];
		}

		$decoded = $this->decoded(text: $text, brackets: '[]');
		if ($decoded !== null) {
			return $decoded;
		}

		if (str_contains($text, ',') === true) {
			return array_map(fn (string $part): string => $this->unquoted(part: trim($part)), explode(',', $text));
		}

		return [$text];
	}//end listOfText()

	/**
	 * Strip one pair of surrounding quotes.
	 *
	 * @param string $part The list element
	 *
	 * @return string The element without its quotes
	 */
	private function unquoted(string $part): string {
		$double = str_starts_with($part, '"') === true && str_ends_with($part, '"') === true;
		$single = str_starts_with($part, "'") === true && str_ends_with($part, "'") === true;
		if ($double === true || $single === true) {
			return substr($part, 1, -1);
		}

		return $part;
	}//end unquoted()

	/**
	 * Decode text between the given brackets as JSON.
	 *
	 * @param string $text     The text
	 * @param string $brackets The opening and closing bracket
	 *
	 * @return array<int|string, mixed>|null The decoded value; null when the text holds none
	 */
	private function decoded(string $text, string $brackets): ?array {
		if (str_starts_with($text, $brackets[0]) === false || str_ends_with($text, $brackets[1]) === false) {
			return null;
		}

		return json_decode($text, true);
	}//end decoded()
}//end class
