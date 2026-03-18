<?php

/**
 * Handles allOf/oneOf/anyOf JSON Schema composition for GraphQL type generation.
 *
 * Extracted from SchemaGenerator to reduce class complexity.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL\SchemaGenerator
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\GraphQL\SchemaGenerator;

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
class CompositionHandler
{

    /**
     * Callback to resolve a $ref string to a RegisterSchema.
     *
     * @var callable(string): ?RegisterSchema
     */
    private $refResolver;

    /**
     * Callback to get/create an ObjectType for a schema.
     *
     * @var callable(RegisterSchema): ObjectType
     */
    private $objectTypeFactory;

    /**
     * Callback to build object fields for a schema.
     *
     * @var callable(RegisterSchema): array<string, array<string, mixed>>
     */
    private $fieldBuilder;

    /**
     * Callback to convert a slug to a PascalCase type name.
     *
     * @var callable(string, ?int): string
     */
    private $typeNameConverter;

    /**
     * Constructor.
     *
     * @param callable $refResolver       Resolves a $ref to a RegisterSchema
     * @param callable $objectTypeFactory Gets/creates an ObjectType for a schema
     * @param callable $fieldBuilder      Builds object fields for a schema
     * @param callable $typeNameConverter Converts a slug to a PascalCase type name
     */
    public function __construct(
        callable $refResolver,
        callable $objectTypeFactory,
        callable $fieldBuilder,
        callable $typeNameConverter,
    ) {
        $this->refResolver       = $refResolver;
        $this->objectTypeFactory = $objectTypeFactory;
        $this->fieldBuilder      = $fieldBuilder;
        $this->typeNameConverter = $typeNameConverter;

    }//end __construct()

    /**
     * Apply allOf/oneOf/anyOf composition to existing fields.
     *
     * Merges referenced schema fields (allOf), adds union type field (oneOf),
     * and/or adds interface type field (anyOf) to the provided field array.
     *
     * @param RegisterSchema       $schema The register schema
     * @param array<string, mixed> $fields The fields array to modify in-place
     *
     * @return void
     */
    public function applyComposition(RegisterSchema $schema, array &$fields): void
    {
        $this->applyAllOf(schema: $schema, fields: $fields);
        $this->applyOneOf(schema: $schema, fields: $fields);
        $this->applyAnyOf(schema: $schema, fields: $fields);

    }//end applyComposition()

    /**
     * Apply allOf composition: merge fields from referenced schemas.
     *
     * @param RegisterSchema       $schema The register schema
     * @param array<string, mixed> $fields The fields array to modify in-place
     *
     * @return void
     */
    private function applyAllOf(RegisterSchema $schema, array &$fields): void
    {
        $allOf = null;
        if (method_exists(object_or_class: $schema, method: 'getAllOf') === true) {
            $allOf = $schema->getAllOf();
        }

        if (is_array(value: $allOf) === false) {
            return;
        }

        foreach ($allOf as $ref) {
            $refSlug = null;
            if (is_array(value: $ref) === true) {
                $refSlug = ($ref['$ref'] ?? null);
            } else {
                $refSlug = $ref;
            }

            $refSchema = null;
            if ($refSlug !== null) {
                $refSchema = ($this->refResolver)($refSlug);
            }

            if ($refSchema !== null) {
                $refFields = ($this->fieldBuilder)($refSchema);
                // Merge, giving priority to the current schema's fields.
                $fields = array_merge($refFields, $fields);
            }
        }

    }//end applyAllOf()

    /**
     * Apply oneOf composition: create a union type field.
     *
     * @param RegisterSchema       $schema The register schema
     * @param array<string, mixed> $fields The fields array to modify in-place
     *
     * @return void
     */
    private function applyOneOf(RegisterSchema $schema, array &$fields): void
    {
        $oneOf = null;
        if (method_exists(object_or_class: $schema, method: 'getOneOf') === true) {
            $oneOf = $schema->getOneOf();
        }

        if (is_array(value: $oneOf) === false || empty($oneOf) === true) {
            return;
        }

        $unionTypes = $this->resolveCompositionRefs(refs: $oneOf);
        if (empty($unionTypes) === true) {
            return;
        }

        $oneOfSlug = $schema->getSlug();
        if ($oneOfSlug === null || $oneOfSlug === '') {
            $oneOfSlug = 'Schema'.$schema->getId();
        }

        $typeName         = ($this->typeNameConverter)($oneOfSlug, null);
        $unionType        = new UnionType(
            [
                'name'  => $typeName.'Union',
                'types' => $unionTypes,
            ]
        );
        $fields['_oneOf'] = [
            'type'        => $unionType,
            'description' => 'One of the composed types',
        ];

    }//end applyOneOf()

    /**
     * Apply anyOf composition: create an interface type with shared fields.
     *
     * @param RegisterSchema       $schema The register schema
     * @param array<string, mixed> $fields The fields array to modify in-place
     *
     * @return void
     */
    private function applyAnyOf(RegisterSchema $schema, array &$fields): void
    {
        $anyOf = null;
        if (method_exists(object_or_class: $schema, method: 'getAnyOf') === true) {
            $anyOf = $schema->getAnyOf();
        }

        if (is_array(value: $anyOf) === false || empty($anyOf) === true) {
            return;
        }

        $anyOfTypes = $this->resolveCompositionRefs(refs: $anyOf);
        if (empty($anyOfTypes) === true) {
            return;
        }

        // Build interface from shared fields across all anyOf types.
        $sharedFields = $this->extractSharedFields(types: $anyOfTypes);
        if (empty($sharedFields) === true) {
            return;
        }

        $anyOfSlug = $schema->getSlug();
        if ($anyOfSlug === null || $anyOfSlug === '') {
            $anyOfSlug = 'Schema'.$schema->getId();
        }

        $typeName         = ($this->typeNameConverter)($anyOfSlug, null);
        $interfaceType    = new InterfaceType(
            [
                'name'   => $typeName.'Interface',
                'fields' => $sharedFields,
            ]
        );
        $fields['_anyOf'] = [
            'type'        => $interfaceType,
            'description' => 'Any of the composed types (shared fields)',
        ];

    }//end applyAnyOf()

    /**
     * Resolve an array of composition references ($ref strings) to ObjectType instances.
     *
     * @param array<mixed> $refs The composition references
     *
     * @return ObjectType[] The resolved object types
     */
    private function resolveCompositionRefs(array $refs): array
    {
        $types = [];
        foreach ($refs as $ref) {
            $refSlug = null;
            if (is_array(value: $ref) === true) {
                $refSlug = ($ref['$ref'] ?? null);
            } else if (is_string(value: $ref) === true) {
                $refSlug = $ref;
            }

            if ($refSlug === null) {
                continue;
            }

            $refSchema = ($this->refResolver)($refSlug);
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
     * @return array<string, array<string, mixed>> Shared field configs
     */
    private function extractSharedFields(array $types): array
    {
        if (empty($types) === true) {
            return [];
        }

        // Get field names from first type.
        $firstType  = $types[0];
        $firstNames = $firstType->getFieldNames();
        $shared     = [];

        foreach ($firstNames as $fieldName) {
            // Skip metadata fields — they're always present.
            if (str_starts_with(haystack: $fieldName, needle: '_') === true) {
                continue;
            }

            // Check if all other types also have this field.
            $allHave = true;
            foreach ($types as $type) {
                if ($type->hasField(name: $fieldName) === false) {
                    $allHave = false;
                    break;
                }
            }

            if ($allHave === true) {
                $field = $firstType->getField(name: $fieldName);
                $shared[$fieldName] = [
                    'type'        => $field->getType(),
                    'description' => $field->description,
                ];
            }
        }//end foreach

        return $shared;

    }//end extractSharedFields()
}//end class
