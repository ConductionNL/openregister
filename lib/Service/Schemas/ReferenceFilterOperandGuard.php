<?php

/**
 * Reference Filter Operand Guard
 *
 * Refuses a reference filter that names a property neither schema declares,
 * at schema save, where the author is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Db\SchemaMapper;

/**
 * The call site for `ReferenceFilterDeclaration::assertOperandsExist()`.
 *
 * 🔴 THE CHECK EXISTED AND NOTHING CALLED IT. `assertOperandsExist()` shipped
 * with a full declaration, and the comment beside `validateProperty()` said
 * the operands were checked "in SchemasController", which never mentioned the
 * class. A guard with no call site is identical to having no guard: an author
 * saving a filter that reads a property this schema does not declare got a
 * clean 201, and found out later from a picker that silently offered
 * everything, with no message saying which of the two schemas was missing the
 * property.
 *
 * It lives in its own class rather than inside the controller because the
 * question needs BOTH schemas in hand: `PropertyValidatorHandler` sees one
 * property at a time and cannot answer it, and the controller would have to
 * grow schema resolution to do so.
 */
class ReferenceFilterOperandGuard {
	/**
	 * Constructor
	 *
	 * @param SchemaMapper $schemaMapper Resolves the referenced schema so its properties can be read.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()

	/**
	 * Refuse every filter in this payload that names a property nobody declares.
	 *
	 * @param array<string, mixed> $properties The `properties` block of the schema being saved.
	 *
	 * @return void
	 *
	 * @throws ReferenceFilterException When an operand is not declared on either schema.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md#requirement-a-reference-property-may-narrow-its-choices-with-a-query-over-the-record-req-fuc-003
	 */
	public function assertProperties(array $properties): void {
		foreach ($properties as $name => $config) {
			if (is_array($config) === false) {
				continue;
			}

			$declaration = ReferenceFilterDeclaration::fromProperty(property: $config, path: (string)$name);
			if ($declaration === null) {
				continue;
			}

			$declaration->assertOperandsExist(
				ownProperties: $properties,
				farProperties: $this->propertiesOf(config: $config),
				path: (string)$name
			);
		}
	}//end assertProperties()

	/**
	 * The properties of the schema a reference points at.
	 *
	 * An empty array when the target cannot be resolved, which is not a
	 * refusal: a schema may legitimately reference one that has not been
	 * imported yet, and `assertOperandsExist()` treats an empty far side as
	 * "nothing to check there" while still checking this side. Refusing on an
	 * unresolvable target would make the order of an import decide whether a
	 * schema saves.
	 *
	 * @param array<string, mixed> $config The reference property's declaration.
	 *
	 * @return array<string, mixed> The referenced schema's properties, or an empty array.
	 */
	private function propertiesOf(array $config): array {
		$target = $this->targetOf(config: $config);
		if ($target === null) {
			return [];
		}

		try {
			$schema = $this->schemaMapper->find(id: $target);
		} catch (\Throwable $e) {
			return [];
		}

		$properties = $schema->getProperties();
		if (is_array($properties) === false) {
			return [];
		}

		return $properties;
	}//end propertiesOf()

	/**
	 * The identifier of the schema a reference property points at.
	 *
	 * Mirrors what a reference means everywhere else in this app: `$ref` or
	 * `schema` on the property, and for an array of references, the same two
	 * keys on its `items`.
	 *
	 * @param array<string, mixed> $config The reference property's declaration.
	 *
	 * @return string|null The target identifier, or null when the property names none.
	 */
	private function targetOf(array $config): ?string {
		foreach (['$ref', 'schema'] as $key) {
			$value = ($config[$key] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		$items = ($config['items'] ?? null);
		if (is_array($items) === true) {
			return $this->targetOf(config: $items);
		}

		return null;
	}//end targetOf()
}//end class
