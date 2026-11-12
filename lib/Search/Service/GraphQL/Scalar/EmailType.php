<?php

/**
 * Email scalar type for GraphQL.
 *
 * Validates and handles RFC 5321 email address strings.
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

/**
 * Email scalar type validating RFC 5321 email format.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
 */
class EmailType extends FormattedStringType {

	/**
	 * The name of this scalar type.
	 *
	 * @var string
	 */
	public string $name = 'Email';

	/**
	 * The description of this scalar type.
	 *
	 * @var string|null
	 */
	public ?string $description = 'RFC 5321 email address string.';

	/**
	 * The noun naming a value of this format in the invalid-value message.
	 *
	 * @return string The noun.
	 */
	protected function formatNoun(): string {
		return 'email';
	}//end formatNoun()

	/**
	 * Whether a string is in this scalar's format.
	 *
	 * @param string $value The string.
	 *
	 * @return bool True when it is.
	 */
	protected function isWellFormed(string $value): bool {
		return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
	}//end isWellFormed()
}//end class
