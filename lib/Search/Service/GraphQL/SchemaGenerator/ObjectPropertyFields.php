<?php

/**
 * Object fields declared by a schema's properties.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

namespace OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;

use GraphQL\Type\Definition\Type;
use OCA\OpenRegister\Db\Schema as RegisterSchema;

/**
 * Maps the properties of a schema to GraphQL object fields.
 */
class ObjectPropertyFields {

	/**
	 * The field that states how many properties were withheld from the caller.
	 */
	public const WITHHELD_FIELD = '_withheldProperties';

	/**
	 * Constructor.
	 *
	 * @param TypeMapperHandler $typeMapper Type mapping and authorisation notes
	 * @param GraphQLNames $names Field names
	 */
	public function __construct(
		private readonly TypeMapperHandler $typeMapper,
		private readonly GraphQLNames $names,
	) {
	}//end __construct()

	/**
	 * The fields of every array-shaped property of a schema.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return array<string, array<string, mixed>> The fields by name
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function forSchema(RegisterSchema $schema): array {
		$notes = $this->typeMapper->getPropertyAuthDescriptions(schema: $schema);
		$fields = [];
		$withheld = 0;

		foreach (($schema->getProperties() ?? []) as $name => $property) {
			if (is_array(value: $property) === false) {
				continue;
			}

			if ($this->typeMapper->mayCarryField(schema: $schema, property: (string)$name) === false) {
				$withheld++;
				continue;
			}

			$fields[$this->names->toFieldName(slug: $name)] = [
				'type' => $this->typeMapper->mapPropertyToGraphQLType(property: $property),
				'description' => $this->description(property: $property, note: ($notes[$name] ?? null)),
			];
		}

		if ($withheld > 0) {
			$fields[self::WITHHELD_FIELD] = $this->withheldField(withheld: $withheld);
		}

		return $fields;
	}//end forSchema()

	/**
	 * The field stating how many properties this caller's type leaves out.
	 *
	 * A count, never names, as the OpenAPI description states it: the caller learns
	 * the type is not the whole schema without learning what is missing.
	 *
	 * @param int $withheld The number of properties left out.
	 *
	 * @return array<string, mixed> The field
	 *
	 * @spec openspec/changes/schema-shape-exposure/specs/rbac-scopes/spec.md
	 */
	private function withheldField(int $withheld): array {
		return [
			'type' => Type::nonNull(Type::int()),
			'description' => 'How many properties of this schema are withheld from this caller',
			'resolve' => static fn (): int => $withheld,
		];
	}//end withheldField()

	/**
	 * A property's description with its authorisation note appended.
	 *
	 * A property without a description gets the bare note, not ". Requires ...".
	 *
	 * @param array<string, mixed> $property The property definition
	 * @param string|null $note The authorisation note, if any
	 *
	 * @return string|null The description
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function description(array $property, ?string $note): ?string {
		$parts = array_filter(array: [($property['description'] ?? ''), $note], callback: fn ($part) => $part !== '' && $part !== null);
		if ($parts === []) {
			return null;
		}

		return implode(separator: '. ', array: $parts);
	}//end description()
}//end class
