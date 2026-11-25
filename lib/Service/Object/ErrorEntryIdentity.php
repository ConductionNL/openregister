<?php

/**
 * OpenRegister ErrorEntryIdentity
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

use Opis\JsonSchema\Errors\ValidationError;

/**
 * The keyword and property an error entry of a failed validation names.
 *
 * The message of the entry is built from the same error (see
 * {@see DeclaredErrorMessage}): the first nested error under a keyword that only
 * wraps one (`properties`, `items`, ...). A `required` error is reported on the
 * parent object, so its property is the first missing one. The property is the
 * dotted path of the failing value, null for the document itself.
 *
 * @spec openspec/changes/modelling-validation-messages/specs/schema-validation-messages/spec.md#requirement-a-failed-rule-returns-the-declared-message-in-the-request-language
 */
final class ErrorEntryIdentity {

	/**
	 * Keywords that only wrap the error of a nested value; the same list DeclaredErrorMessage descends.
	 *
	 * @var array<int, string>
	 */
	private const WRAPPERS = ['properties', 'items', 'prefixItems', 'additionalProperties', 'allOf', '$ref'];

	/**
	 * The keyword and property of an error.
	 *
	 * @param ValidationError $error The error Opis reported.
	 *
	 * @return array{keyword: string, property: string|null} The failed keyword and the property's dotted path.
	 *
	 * @spec openspec/changes/modelling-validation-messages/specs/schema-validation-messages/spec.md#requirement-a-failed-rule-returns-the-declared-message-in-the-request-language
	 */
	public function of(ValidationError $error): array {
		$leaf = self::leaf(error: $error);
		$path = array_map('strval', $leaf->data()->fullPath());
		if ($leaf->keyword() === 'required') {
			$missing = (array)($leaf->args()['missing'] ?? []);
			if ($missing !== []) {
				$path[] = (string)reset($missing);
			}
		}

		$property = null;
		if ($path !== []) {
			$property = implode('.', $path);
		}

		return ['keyword' => $leaf->keyword(), 'property' => $property];
	}//end of()

	/**
	 * The error the message is built from: the first nested error under a wrapping keyword.
	 *
	 * @param ValidationError $error The error.
	 *
	 * @return ValidationError The leaf error.
	 */
	private static function leaf(ValidationError $error): ValidationError {
		while (in_array($error->keyword(), self::WRAPPERS, true) === true && $error->subErrors() !== []) {
			$error = $error->subErrors()[0];
		}

		return $error;
	}//end leaf()
}//end class
