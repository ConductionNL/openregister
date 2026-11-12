<?php

/**
 * UUID scalar type for GraphQL.
 *
 * Validates and handles UUID v4 format strings.
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

use OCA\OpenRegister\Formats\UuidFormat;

/**
 * UUID scalar type validating UUID v4 format.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
 */
class UuidType extends FormattedStringType {

	/**
	 * The name of this scalar type.
	 *
	 * @var string
	 */
	public string $name = 'UUID';

	/**
	 * The description of this scalar type.
	 *
	 * @var string|null
	 */
	public ?string $description = 'UUID v4 string (e.g., 550e8400-e29b-41d4-a716-446655440000).';

	/**
	 * The noun naming a value of this format in the invalid-value message.
	 *
	 * @return string The noun.
	 */
	protected function formatNoun(): string {
		return 'UUID';
	}//end formatNoun()

	/**
	 * Whether a string is in this scalar's format.
	 *
	 * @param string $value The string.
	 *
	 * @return bool True when it is.
	 */
	protected function isWellFormed(string $value): bool {
		return UuidFormat::isCanonical(value: $value) === true;
	}//end isWellFormed()
}//end class
