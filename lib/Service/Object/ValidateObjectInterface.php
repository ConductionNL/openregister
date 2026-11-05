<?php

/**
 * ValidateObjectInterface: the public surface of ValidateObject, for callers to type against.
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

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCP\AppFramework\Http\JSONResponse;
use Opis\JsonSchema\ValidationResult;
use Opis\Uri\Uri;
use stdClass;

/**
 * Every public method of ValidateObject. Callers depend on this interface, not on the class.
 */
interface ValidateObjectInterface {

	/**
	 * Enforce JSON-Schema `readOnly: true` on the UPDATE write path.
	 *
	 * For each top-level property declared `readOnly: true` on the schema:
	 *  - If the incoming payload omits the property → OK (no violation).
	 *  - If the incoming payload includes the property AND its value differs
	 *    from the previously-stored value → reject with a structured error.
	 *  - If the value matches the previously-stored value → OK (no-op write).
	 *
	 * CREATE is intentionally NOT covered: there's no prior value to violate,
	 * and the canonical use of `readOnly` is "server-stamped on create,
	 * immutable afterwards." For CREATE-time immutability use `const` or a
	 * `default: …` with `defaultBehavior: 'always'` (both already enforced by
	 * SaveObject::setDefaultValues).
	 *
	 * Wave-12 Fix 1. Audited gap at `/tmp/wave11-or-engine-primitives.md`
	 * Section A: prior to this method, `readOnly: true` was pure metadata —
	 * `SchemaMapper.php:2303-2304` flagged it as a "freely overridable
	 * metadataField," with no write-path enforcement anywhere.
	 *
	 * Returns the list of violations, empty when the update is compliant. Callers
	 * MUST throw `ValidationException` (or return an equivalent 422 response) when
	 * this method returns a non-empty list.
	 *
	 * @param array $incomingObject The candidate object data (top-level keys
	 *                              are property names).
	 * @param array $existingObject The previously-stored object data (same
	 *                              shape). Pass the empty array `[]` to
	 *                              opt out (CREATE / no existing record).
	 * @param Schema $schema The schema whose `readOnly` declarations
	 *                       drive enforcement.
	 *
	 * @return array<int, array{property: string, attempted: mixed, stored: mixed, message: string}>
	 */
	public function validateReadOnlyConstraints(array $incomingObject, array $existingObject, Schema $schema): array;

	/**
	 * Enforce `immutable: true` on the UPDATE write path.
	 *
	 * ⚠️ NOT a synonym for `readOnly`, and the difference is the whole point.
	 * `readOnly` refuses any value that differs from what is stored, including
	 * the FIRST one, so a property that was never filled in can never be filled
	 * in. `immutable` means "once set": while the stored value is absent or
	 * null the property is freely writable, and from the first real value
	 * onwards every change is refused.
	 *
	 * That is what a vastgesteld besluit and a final document actually ask for.
	 * The rule holds whatever the object's state and whoever the actor is, so
	 * it fires on an open object as readily as on a frozen one — which is why
	 * it lives on the property rather than on the state.
	 *
	 * Returns the list of violations, empty when the update is compliant.
	 * Callers MUST refuse the write when this returns a non-empty list.
	 *
	 * @param array $incomingObject The candidate object data.
	 * @param array $existingObject The previously-stored object data. Pass `[]`
	 *                              to opt out (CREATE / no existing record).
	 * @param Schema $schema The schema whose declarations drive enforcement.
	 *
	 * @return array<int, array{property: string, attempted: mixed, stored: mixed, message: string}>
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function validateImmutableConstraints(array $incomingObject, array $existingObject, Schema $schema): array;

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
	 * Resolves a schema from a given URI.
	 *
	 * @param Uri $uri The URI pointing to the schema.
	 *
	 * @return string The schema content in JSON format.
	 *
	 * @throws GuzzleException If there is an error during schema fetching.
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uri::fromParts is standard GuzzleHttp\Psr7 pattern
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function resolveSchema(Uri $uri): string;

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

	/**
	 * Handles validation exceptions by formatting them into a JSON response.
	 *
	 * @param ValidationException|CustomValidationException $exception The validation exception.
	 *
	 * @return JSONResponse JSON error response with validation errors and 400 status code.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function handleValidationException(ValidationException|CustomValidationException $exception): JSONResponse;
}//end interface
