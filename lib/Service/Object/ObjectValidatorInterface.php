<?php

/**
 * ObjectValidatorInterface: validates one object against its schema.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;
use Opis\JsonSchema\ValidationResult;
use stdClass;

/**
 * The object validation of ValidateObject and the message it renders.
 *
 * Callers that need only this role depend on it, not on the whole class.
 */
interface ObjectValidatorInterface {

	/**
	 * Validates an object against a schema, letting a `user` format accept the given account ids.
	 *
	 * For a caller that writes those accounts before the object, such as an
	 * example set's check. Every other account id is still asked of the user
	 * manager, and the ids are accepted for this one call only.
	 *
	 * @param array                     $object       The object to validate.
	 * @param Schema|int|null           $schema       The schema or schema ID to validate against.
	 * @param object                    $schemaObject A custom schema object for validation.
	 * @param array<int, string>        $plannedUsers Account ids to accept although they do not exist yet.
	 *
	 * @return ValidationResult The result of the validation.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function validateObjectWithPlannedUsers(
		array $object, Schema|int|null $schema = null, object $schemaObject = new stdClass(), array $plannedUsers = []
	): ValidationResult;

	/**
	 * Validates an object against a schema.
	 *
	 * @param array $object The object to validate.
	 * @param Schema|int|null $schema The schema or schema ID to validate against.
	 * @param object $schemaObject A custom schema object for validation.
	 * @param int $_depth The depth level for validation (unused).
	 * @param array<string, string> $notSupplied Properties recorded as not supplied, mapped to their reason codes.
	 *
	 * @return ValidationResult The result of the validation.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function validateObject(
		array $object, Schema|int|string|null $schema = null, object $schemaObject = new stdClass(), int $_depth = 0,
		array $notSupplied = []
	): ValidationResult;

	/**
	 * Generates a meaningful error message from a validation result.
	 *
	 * This method creates clear, user-friendly error messages instead of using
	 * the generic Opis error message like "The required properties ({missing}) are missing".
	 *
	 * @param ValidationResult $result The validation result from Opis JsonSchema.
	 *
	 * @return string A meaningful error message.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function generateErrorMessage(ValidationResult $result): string;
}//end interface
