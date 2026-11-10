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

use OCA\OpenRegister\Db\SchemaMapperInterface;

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
	 * @param SchemaMapperInterface $schemaMapper Resolves the referenced schema so its properties can be read.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
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
			if (is_array($config) === true) {
				$this->assertProperty(name: (string)$name, config: $config, properties: $properties);
			}
		}
	}//end assertProperties()

	/**
	 * Refuse the filter of one property when it names a property nobody declares.
	 *
	 * @param string               $name       The property name, which is also its path.
	 * @param array<string, mixed> $config     The property declaration.
	 * @param array<string, mixed> $properties The `properties` block of the schema being saved.
	 *
	 * @return void
	 *
	 * @throws ReferenceFilterException When an operand is not declared on either schema.
	 */
	private function assertProperty(string $name, array $config, array $properties): void {
		$declaration = ReferenceFilterDeclaration::fromProperty(property: $config, path: $name);
		if ($declaration === null) {
			return;
		}

		$declaration->assertOperandsExist(ownProperties: $properties, farProperties: $this->propertiesOf(config: $config), path: $name);
	}//end assertProperty()

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
		$target = ReferenceFilterDeclaration::targetSchemaOf(config: $config);
		if ($target === null) {
			return [];
		}

		try {
			$schema = $this->schemaMapper->find(id: $target);
		} catch (\Throwable $e) {
			return [];
		}

		return self::arrayOrEmpty(value: $schema->getProperties());
	}//end propertiesOf()

	/**
	 * A value when it is an array, else an empty array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<string, mixed> The array.
	 */
	private static function arrayOrEmpty(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end arrayOrEmpty()
}//end class
