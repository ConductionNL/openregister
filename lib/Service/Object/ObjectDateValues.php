<?php

/**
 * OpenRegister ObjectDateValues
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Service\DateTimeNormalizer;

/**
 * Normalises the date values of object data before validation.
 *
 * A `date` property accepts a datetime string and keeps its date part; a
 * `date-time` property keeps a valid string and clears an invalid one, so
 * downstream validation sees what the caller sent. An empty or whitespace-only
 * string becomes null rather than the current datetime through PHP's
 * `new DateTime('')`.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectDateValues {

	/**
	 * Hold the parser.
	 *
	 * @param DateTimeNormalizer $dates Parses user-supplied datetimes.
	 */
	public function __construct(private readonly DateTimeNormalizer $dates) {
	}//end __construct()

	/**
	 * The object data with its date values normalised.
	 *
	 * @param array<string, mixed> $object     The object data.
	 * @param array<mixed>         $properties The schema properties.
	 *
	 * @return array<string, mixed>
	 */
	public function normalize(array $object, array $properties): array {
		foreach ($properties as $name => $definition) {
			$value  = ($object[$name] ?? null);
			$format = $this->dateFormatOf(definition: $definition);
			if (is_string($value) === true && $format !== null) {
				$object[$name] = $this->normalized(value: $value, format: $format);
			}
		}

		return $object;
	}//end normalize()

	/**
	 * The date format a property declares, if any.
	 *
	 * @param mixed $definition The property definition.
	 *
	 * @return string|null `date`, `date-time` or null.
	 */
	private function dateFormatOf(mixed $definition): ?string {
		$format = ($definition['format'] ?? null);
		if ($format === 'date' || $format === 'date-time') {
			return $format;
		}

		return null;
	}//end dateFormatOf()

	/**
	 * One normalised value.
	 *
	 * @param string $value  The value.
	 * @param string $format `date` or `date-time`.
	 *
	 * @return string|null
	 */
	private function normalized(string $value, string $format): ?string {
		if (trim($value) === '') {
			return null;
		}

		if ($format === 'date') {
			return $this->dateOnly(value: $value);
		}

		if ($this->dates->normalize($value) === null) {
			return null;
		}

		return $value;
	}//end normalized()

	/**
	 * The date part of a value; a value that does not parse stays as it is.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private function dateOnly(string $value): string {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			return $value;
		}

		$parsed = $this->dates->normalize($value);
		if ($parsed === null) {
			return $value;
		}

		return $parsed->format('Y-m-d');
	}//end dateOnly()
}//end class
