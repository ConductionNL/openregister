<?php

/**
 * Handles allOf/oneOf/anyOf JSON Schema composition for GraphQL type generation.
 *
 * Extracted from SchemaGenerator to reduce class complexity.
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
 */

namespace OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;

use Closure;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\UnionType;
use OCA\OpenRegister\Db\Schema as RegisterSchema;

/**
 * Handles JSON Schema composition keywords (allOf, oneOf, anyOf) for GraphQL.
 *
 * Resolves $ref references within composition arrays and builds the appropriate
 * GraphQL types: merged fields for allOf, UnionType for oneOf, InterfaceType
 * for anyOf.
 *
 * @psalm-suppress UnusedClass
 */
class CompositionHandler {

	/**
	 * Constructor.
	 *
	 * @param Closure(string): ?RegisterSchema                                   $refResolver       Resolves a $ref to a RegisterSchema
	 * @param Closure(RegisterSchema): ObjectType                               $objectTypeFactory Gets/creates an ObjectType for a schema
	 * @param Closure(RegisterSchema): array<string, array<string, mixed>>      $fieldBuilder      Builds object fields for a schema
	 * @param Closure(string, ?int): string                                     $typeNameConverter Converts a slug to a PascalCase type name
	 */
	public function __construct(
		private readonly Closure $refResolver,
		private readonly Closure $objectTypeFactory,
		private readonly Closure $fieldBuilder,
		private readonly Closure $typeNameConverter,
	) {
	}//end __construct()

	/**
	 * Apply allOf/oneOf/anyOf composition to existing fields.
	 *
	 * Merges referenced schema fields (allOf), adds union type field (oneOf),
	 * and/or adds interface type field (anyOf) to the provided field array.
	 *
	 * @param RegisterSchema $schema The register schema
	 * @param array<string, mixed> $fields The fields array to modify in-place
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function applyComposition(RegisterSchema $schema, array &$fields): void {
		$this->applyAllOf(schema: $schema, fields: $fields);
		$this->applyOneOf(schema: $schema, fields: $fields);
		$this->applyAnyOf(schema: $schema, fields: $fields);

	}//end applyComposition()

	/**
	 * Apply allOf composition: merge fields from referenced schemas.
	 *
	 * @param RegisterSchema $schema The register schema
	 * @param array<string, mixed> $fields The fields array to modify in-place
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function applyAllOf(RegisterSchema $schema, array &$fields): void {
		$allOf = $schema->getAllOf();
		if (is_array(value: $allOf) === false) {
			return;
		}

		foreach ($allOf as $ref) {
			$refSchema = $this->resolveRef(refSlug: $this->refSlug(ref: $ref));
			if ($refSchema !== null) {
				// Merge, giving priority to the current schema's fields.
				$fields = array_merge(($this->fieldBuilder)($refSchema), $fields);
			}
		}

	}//end applyAllOf()

	/**
	 * Apply oneOf composition: create a union type field.
	 *
	 * @param RegisterSchema $schema The register schema
	 * @param array<string, mixed> $fields The fields array to modify in-place
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function applyOneOf(RegisterSchema $schema, array &$fields): void {
		$oneOf = $schema->getOneOf();
		if (is_array(value: $oneOf) === false) {
			return;
		}

		$unionTypes = $this->resolveCompositionRefs(refs: $oneOf);
		if (empty($unionTypes) === true) {
			return;
		}

		$unionType = new UnionType(
			['name' => $this->compositeTypeName(schema: $schema) . 'Union', 'types' => $unionTypes]
		);
		$fields['_oneOf'] = ['type' => $unionType, 'description' => 'One of the composed types'];

	}//end applyOneOf()

	/**
	 * Apply anyOf composition: create an interface type with shared fields.
	 *
	 * @param RegisterSchema $schema The register schema
	 * @param array<string, mixed> $fields The fields array to modify in-place
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function applyAnyOf(RegisterSchema $schema, array &$fields): void {
		$anyOf = $schema->getAnyOf();
		if (is_array(value: $anyOf) === false) {
			return;
		}

		$sharedFields = $this->extractSharedFields(types: $this->resolveCompositionRefs(refs: $anyOf));
		if (empty($sharedFields) === true) {
			return;
		}

		$interfaceType = new InterfaceType(
			['name' => $this->compositeTypeName(schema: $schema) . 'Interface', 'fields' => $sharedFields]
		);
		$fields['_anyOf'] = ['type' => $interfaceType, 'description' => 'Any of the composed types (shared fields)'];

	}//end applyAnyOf()

	/**
	 * Build the PascalCase base name for a schema's composite types.
	 *
	 * A schema without a slug is named after its id.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return string The type name
	 */
	private function compositeTypeName(RegisterSchema $schema): string {
		$slug = $schema->getSlug();
		if ($slug === null || $slug === '') {
			$slug = 'Schema' . $schema->getId();
		}

		return ($this->typeNameConverter)($slug, null);

	}//end compositeTypeName()

	/**
	 * Read the reference out of a composition entry.
	 *
	 * An array entry carries it under `$ref`; any other entry is the reference itself.
	 *
	 * @param mixed $ref The composition entry
	 *
	 * @return mixed The reference, or null when an array entry has none
	 */
	private function refSlug(mixed $ref): mixed {
		if (is_array(value: $ref) === true) {
			return ($ref['$ref'] ?? null);
		}

		return $ref;

	}//end refSlug()

	/**
	 * Resolve a reference to a schema; a null reference resolves to nothing.
	 *
	 * @param mixed $refSlug The reference
	 *
	 * @return RegisterSchema|null The referenced schema
	 */
	private function resolveRef(mixed $refSlug): ?RegisterSchema {
		if ($refSlug === null) {
			return null;
		}

		return ($this->refResolver)($refSlug);

	}//end resolveRef()

	/**
	 * Resolve an array of composition references ($ref strings) to ObjectType instances.
	 *
	 * Only array and string entries count; other entries are skipped.
	 *
	 * @param array<mixed> $refs The composition references
	 *
	 * @return ObjectType[] The resolved object types
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function resolveCompositionRefs(array $refs): array {
		$types = [];
		foreach ($refs as $ref) {
			if (is_array(value: $ref) === false && is_string(value: $ref) === false) {
				continue;
			}

			$refSchema = $this->resolveRef(refSlug: $this->refSlug(ref: $ref));
			if ($refSchema !== null) {
				$types[] = ($this->objectTypeFactory)($refSchema);
			}
		}

		return $types;
	}//end resolveCompositionRefs()

	/**
	 * Extract shared fields across multiple ObjectTypes for interface generation.
	 *
	 * @param ObjectType[] $types The object types to intersect
	 *
	 * @return array<string, array<string, mixed>> Shared field configs, empty when there are no types
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function extractSharedFields(array $types): array {
		$shared = [];
		$firstType = ($types[0] ?? null);
		if ($firstType === null) {
			return $shared;
		}

		foreach ($firstType->getFieldNames() as $fieldName) {
			if ($this->isSharedField(fieldName: $fieldName, types: $types) === true) {
				$field = $firstType->getField(name: $fieldName);
				$shared[$fieldName] = ['type' => $field->getType(), 'description' => $field->description];
			}
		}

		return $shared;
	}//end extractSharedFields()

	/**
	 * Whether every type has the field; metadata fields (leading `_`) never count.
	 *
	 * @param string       $fieldName The field name
	 * @param ObjectType[] $types     The object types to check
	 *
	 * @return bool True when the field is shared
	 */
	private function isSharedField(string $fieldName, array $types): bool {
		if (str_starts_with(haystack: $fieldName, needle: '_') === true) {
			return false;
		}

		foreach ($types as $type) {
			if ($type->hasField(name: $fieldName) === false) {
				return false;
			}
		}

		return true;
	}//end isSharedField()
}//end class
