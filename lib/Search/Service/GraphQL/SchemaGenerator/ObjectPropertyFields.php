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

use OCA\OpenRegister\Db\Schema as RegisterSchema;

/**
 * Maps the properties of a schema to GraphQL object fields.
 */
class ObjectPropertyFields {

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

		foreach (($schema->getProperties() ?? []) as $name => $property) {
			if (is_array(value: $property) === false || $this->typeMapper->mayCarryField(schema: $schema, property: (string)$name) === false) {
				continue;
			}

			$fields[$this->names->toFieldName(slug: $name)] = [
				'type' => $this->typeMapper->mapPropertyToGraphQLType(property: $property),
				'description' => $this->description(property: $property, note: ($notes[$name] ?? null)),
			];
		}

		return $fields;
	}//end forSchema()

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
