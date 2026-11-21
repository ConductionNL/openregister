<?php

/**
 * DateTime scalar type for GraphQL.
 *
 * Handles serialization and parsing of ISO 8601 date-time strings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL\Scalar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Search\Service\GraphQL\Scalar;

use GraphQL\Error\Error;
use GraphQL\Utils\Utils;

/**
 * DateTime scalar type for ISO 8601 date-time strings.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class DateTimeType extends FormattedStringType {

	/**
	 * The name of this scalar type.
	 *
	 * @var string
	 */
	public string $name = 'DateTime';

	/**
	 * The description of this scalar type.
	 *
	 * @var string|null
	 */
	public ?string $description = 'ISO 8601 date-time string (e.g., 2025-01-15T10:30:00+00:00).';

	/**
	 * Serializes a DateTime value to ISO 8601 string.
	 *
	 * @param mixed $value The value to serialize
	 *
	 * @return string The ISO 8601 string
	 *
	 * @throws Error If the value cannot be serialized
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	public function serialize(mixed $value): string {
		if ($value instanceof \DateTimeInterface) {
			return $value->format(\DateTimeInterface::ATOM);
		}

		if (is_string($value) === true) {
			return $value;
		}

		throw new Error(
			'DateTime cannot represent non-date value: ' . Utils::printSafe($value)
		);

	}//end serialize()

	/**
	 * The noun naming a value of this format in the invalid-value message.
	 *
	 * @return string The noun.
	 */
	protected function formatNoun(): string {
		return 'date-time';
	}//end formatNoun()

	/**
	 * Whether a string is an ISO 8601 date-time: ATOM, a local date-time, or a date.
	 *
	 * @param string $value The string.
	 *
	 * @return bool True when it is.
	 */
	protected function isWellFormed(string $value): bool {
		foreach ([\DateTimeInterface::ATOM, 'Y-m-d\TH:i:s', 'Y-m-d'] as $format) {
			if (\DateTimeImmutable::createFromFormat($format, $value) !== false) {
				return true;
			}
		}

		return false;
	}//end isWellFormed()
}//end class
