<?php

/**
 * Property-level RBAC: what one user may read and write of an object's fields.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRules;

/**
 * The contract callers of PropertyRbacHandler type against.
 *
 * Bound in ContractBindings. Every method is described on PropertyRbacHandler.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
interface PropertyRbacHandlerInterface {

	/**
	 * Whether the current user can read a property of this object.
	 *
	 * @param Schema $schema   Schema containing property definition
	 * @param string $property Property name to check
	 * @param array  $object   Object data (for conditional matching)
	 *
	 * @return bool True if user can read the property
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#field-level-security (property-level read authorization:
	 *       evaluates the property's read rules against the current user's groups + object conditions)
	 */
	public function canReadProperty(Schema $schema, string $property, array $object): bool;

	/**
	 * Whether the current user could read a property on some row.
	 *
	 * @param Schema $schema   Schema containing property definition
	 * @param string $property Property name to check
	 *
	 * @return bool True unless no row could ever show this property to the user
	 *
	 * @spec openspec/changes/schema-shape-exposure/specs/rbac-scopes/spec.md
	 */
	public function couldReadProperty(Schema $schema, string $property): bool;

	/**
	 * Whether the current user can update a property of this object.
	 *
	 * @param SchemaProperty        $property The schema and the property name to check
	 * @param PropertyAccessRequest $request  The object data (for conditional matching) and whether this is a create
	 *
	 * @return bool True if user can update the property
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#field-level-security (property-level update authorization,
	 *       with create-mode organisation-match skipping per the FLS-on-create requirement)
	 */
	public function canUpdateProperty(SchemaProperty $property, PropertyAccessRequest $request): bool;

	/**
	 * The object with only the properties the current user may read.
	 *
	 * @param Schema $schema Schema containing property definitions
	 * @param array  $object Object data to filter
	 *
	 * @return array Filtered object
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#fls-must-strip-restricted-fields-from-api-responses-and-export-outputs (strips unreadable
	 *       property-authorized fields from outgoing data; admin + no-property-auth short-circuit)
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-writeonly-properties-must-never-be-returned-on-any-read
	 *       (writeOnly stripping runs before the admin bypass — admin is NOT exempt from writeOnly)
	 */
	public function filterReadableProperties(Schema $schema, array $object): array;

	/**
	 * The object without its write-only properties and paths, for every reader.
	 *
	 * @param Schema $schema Schema containing property definitions
	 * @param array  $object Object data to filter
	 *
	 * @return array Object data with write-only properties removed
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-writeonly-properties-must-never-be-returned-on-any-read
	 *       (strips writeOnly properties for every reader; short-circuits when the schema has none)
	 */
	public function stripWriteOnlyProperties(Schema $schema, array $object): array;

	/**
	 * The declared write-only locations a raw update payload omits.
	 *
	 * @param Schema $schema   Schema whose write-only declarations apply.
	 * @param array  $incoming The raw incoming update payload.
	 *
	 * @return array<int, string> Declared dot-paths absent from the payload
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-omitted-writeonly-value-must-be-preserved-on-save
	 */
	public function collectOmittedWriteOnlyPaths(Schema $schema, array $incoming): array;

	/**
	 * Properties a raw update payload omits that the writer may not read or update.
	 *
	 * @param ProtectedCarryForward $update The schema whose property authorization applies, the raw incoming update
	 *                                      payload and the stored record's match envelope.
	 *
	 * @return array<int, string> Top-level property names to carry forward
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-update-that-omits-a-property-the-caller-may-not-update-keeps-it
	 */
	public function collectOmittedProtectedProperties(ProtectedCarryForward $update): array;

	/**
	 * Carry stored write-only values forward onto a payload that omitted them.
	 *
	 * @param array              $prepared     The prepared update payload.
	 * @param array              $stored       The RAW stored object.
	 * @param array<int, string> $omittedPaths Declared paths absent from the incoming payload.
	 *
	 * @return array The payload with omitted write-only values carried forward
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-omitted-writeonly-value-must-be-preserved-on-save
	 */
	public function restoreWriteOnlyValues(array $prepared, array $stored, array $omittedPaths): array;

	/**
	 * The submitted properties the current user is not allowed to write.
	 *
	 * @param PropertyWriteCheck $write The schema with its property definitions, the existing object data (empty for
	 *                                  creates) with the create flag, and the incoming data from the client.
	 *
	 * @return array Property names the user cannot update
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#field-level-security (validates incoming writes against
	 *       property update rules; skips unchanged values so PATCH may resubmit protected fields)
	 */
	public function getUnauthorizedProperties(PropertyWriteCheck $write): array;

	/**
	 * The lifecycle state's field rules for one object.
	 *
	 * @param Schema $schema Schema containing the lifecycle annotation
	 * @param array  $object Object data to resolve against
	 *
	 * @return StateFieldRules The hidden, read-only and required lists
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function stateFieldRulesFor(Schema $schema, array $object): StateFieldRules;

	/**
	 * Whether the current user is in the admin group.
	 *
	 * @return bool True if user is in admin group
	 *
	 * @spec exclude Trivial admin-group membership check helper; no business logic.
	 */
	public function isAdmin(): bool;
}//end interface
