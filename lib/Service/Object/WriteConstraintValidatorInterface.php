<?php

/**
 * WriteConstraintValidatorInterface: enforces readOnly and immutable properties on an update.
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
use OCA\OpenRegister\Exception\ValidationException;

/**
 * The write-constraint checks of ValidateObject.
 *
 * Callers that need only this role depend on it, not on the whole class.
 */
interface WriteConstraintValidatorInterface {

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
	 * @param WriteConstraintCheck $check  The candidate and the previously-stored object data. An empty
	 *                                     stored side opts out (CREATE / no existing record).
	 * @param Schema               $schema The schema whose `readOnly` declarations drive enforcement.
	 *
	 * @return array<int, array{property: string, attempted: mixed, stored: mixed, message: string}>
	 */
	public function validateReadOnlyConstraints(WriteConstraintCheck $check, Schema $schema): array;

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
	 * @param WriteConstraintCheck $check  The candidate and the previously-stored object data. An empty
	 *                                     stored side opts out (CREATE / no existing record).
	 * @param Schema               $schema The schema whose declarations drive enforcement.
	 *
	 * @return array<int, array{property: string, attempted: mixed, stored: mixed, message: string}>
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function validateImmutableConstraints(WriteConstraintCheck $check, Schema $schema): array;
}//end interface
