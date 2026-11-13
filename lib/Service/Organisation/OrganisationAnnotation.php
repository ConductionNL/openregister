<?php

/**
 * OrganisationAnnotation: the schema annotation that names the property an object's organisation comes from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Organisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-a-schema-can-derive-an-objects-organisation-from-a-property-req-oop-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Organisation;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Relation\RelationAnnotationValidator;

/**
 * Reads and refuses `x-openregister-organisation`.
 *
 * `{"fromProperty": "<property>"}` says that an object's `@self.organisation`
 * follows one of its own values instead of the caller's active organisation,
 * so the unit a record names is the unit its rights are scoped to.
 *
 * The refusal is at save, and blocking: a declaration naming a property the
 * schema does not have cannot resolve anything, so every save of such an
 * object would fall back to the stamping the annotation exists to replace
 * while its author reads a 200 and believes the two are tied together.
 *
 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-a-schema-can-derive-an-objects-organisation-from-a-property-req-oop-001
 */
final class OrganisationAnnotation {

	/**
	 * The annotation key, as `Schema::ANNOTATION_VOCABULARY` holds it.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-organisation';

	/**
	 * The one key the annotation declares.
	 *
	 * @var string
	 */
	public const FROM_PROPERTY = 'fromProperty';

	/**
	 * The property a schema's objects take their organisation from, when it declares one.
	 *
	 * @param Schema|null $schema The schema, or null.
	 *
	 * @return string|null The property name, or null when the schema declares none.
	 *
	 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-a-schema-can-derive-an-objects-organisation-from-a-property-req-oop-001
	 */
	public static function propertyOf(?Schema $schema): ?string {
		if ($schema === null) {
			return null;
		}

		$declaration = (($schema->getConfiguration() ?? [])[self::ANNOTATION] ?? null);
		if (is_object($declaration) === true) {
			$declaration = (array)$declaration;
		}

		if (is_array($declaration) === false) {
			return null;
		}

		$property = ($declaration[self::FROM_PROPERTY] ?? null);
		if (is_string($property) === false || trim($property) === '') {
			return null;
		}

		return $property;
	}//end propertyOf()

	/**
	 * What is wrong with a declaration, as messages, empty when it is sound.
	 *
	 * @param mixed                $declaration The annotation's value.
	 * @param array<string, mixed> $properties  The schema's properties block.
	 *
	 * @return array<int, string> The refusals.
	 *
	 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-a-schema-can-derive-an-objects-organisation-from-a-property-req-oop-001
	 */
	public static function refusals(mixed $declaration, array $properties): array {
		if (is_object($declaration) === true) {
			$declaration = (array)$declaration;
		}

		if (is_array($declaration) === false || array_is_list($declaration) === true) {
			return ['the declaration must be an object with a "' . self::FROM_PROPERTY . '" key.'];
		}

		$property = ($declaration[self::FROM_PROPERTY] ?? null);
		if (is_string($property) === false || trim($property) === '') {
			return ['"' . self::FROM_PROPERTY . '" must name a property of this schema.'];
		}

		if (array_key_exists($property, $properties) === false) {
			return ['"' . $property . '" is not a property of this schema.'];
		}

		if (self::carriesAnOrganisation(property: $properties[$property]) === false) {
			return ['"' . $property . '" must be a string or a reference, so it can name an organisation.'];
		}

		return [];
	}//end refusals()

	/**
	 * Whether a property definition can hold an organisation: a string, or a reference to one.
	 *
	 * @param mixed $property The property definition.
	 *
	 * @return bool True when it can.
	 */
	private static function carriesAnOrganisation(mixed $property): bool {
		if (RelationAnnotationValidator::isReferenceProperty(property: $property) === true) {
			return true;
		}

		if (is_object($property) === true) {
			$property = (array)$property;
		}

		if (is_array($property) === false) {
			return false;
		}

		$type = ($property['type'] ?? null);
		if (is_array($type) === true) {
			$type = array_values(array_filter($type, static fn (mixed $one): bool => $one !== 'null'))[0] ?? null;
		}

		return $type === 'string';
	}//end carriesAnOrganisation()
}//end class
