<?php

/**
 * Root query and mutation fields of the generated GraphQL schema.
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
use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;

/**
 * Collects the query and mutation fields of every schema in the graph.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) webonyx exposes its built-in types statically
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mutation resolvers receive a root they do not read
 */
class SchemaEntryFields {

	/**
	 * Query fields by name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $queryFields = [];

	/**
	 * Mutation fields by name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $mutationFields = [];

	/**
	 * Constructor.
	 *
	 * @param TypeMapperHandler $typeMapper Type mapping and input types
	 * @param GraphQLResolver|null $resolver Answers the fields, null leaves every field answering null
	 */
	public function __construct(
		private readonly TypeMapperHandler $typeMapper,
		private readonly ?GraphQLResolver $resolver,
	) {
	}//end __construct()

	/**
	 * Add the query and mutation fields of one schema.
	 *
	 * @param SchemaEntry $entry The schema
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function add(SchemaEntry $entry): void {
		$this->queryFields[$entry->singular] = $this->singleField(entry: $entry);
		$this->queryFields[$entry->plural] = $this->listField(entry: $entry);

		$title = $entry->titleOr(fallback: $entry->singular);
		$suffix = ucfirst(string: $entry->singular);
		$this->mutationFields['create' . $suffix] = $this->createField(entry: $entry, title: $title);
		$this->mutationFields['update' . $suffix] = $this->updateField(entry: $entry, title: $title);
		$this->mutationFields['delete' . $suffix] = $this->deleteField(entry: $entry, title: $title);
	}//end add()

	/**
	 * The collected query fields.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function queryFields(): array {
		return $this->queryFields;
	}//end queryFields()

	/**
	 * The collected mutation fields.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function mutationFields(): array {
		return $this->mutationFields;
	}//end mutationFields()

	/**
	 * The field that fetches one object.
	 *
	 * @param SchemaEntry $entry The schema
	 *
	 * @return array<string, mixed> The field
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	private function singleField(SchemaEntry $entry): array {
		return [
			'type' => $entry->objectType,
			'args' => ['id' => Type::nonNull(Type::id())],
			'resolve' => $this->singleResolver(schema: $entry->schema),
			'description' => 'Fetch a single ' . $entry->titleOr(fallback: $entry->singular),
		];
	}//end singleField()

	/**
	 * The field that lists objects.
	 *
	 * @param SchemaEntry $entry The schema
	 *
	 * @return array<string, mixed> The field
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	private function listField(SchemaEntry $entry): array {
		$connection = $this->typeMapper->getConnectionType(schema: $entry->schema, objectType: $entry->objectType);

		return [
			'type' => $connection,
			'args' => $this->typeMapper->getListArgs(schema: $entry->schema),
			'resolve' => $this->listResolver(schema: $entry->schema),
			'description' => 'List ' . $entry->titleOr(fallback: $entry->plural) . ' with pagination and filtering',
		];
	}//end listField()

	/**
	 * The field that creates an object.
	 *
	 * @param SchemaEntry $entry The schema
	 * @param string $title The title used in the description
	 *
	 * @return array<string, mixed> The field
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function createField(SchemaEntry $entry, string $title): array {
		$input = $this->typeMapper->getCreateInputType(schema: $entry->schema);

		return [
			'type' => $entry->objectType,
			'args' => ['input' => Type::nonNull($input)],
			'resolve' => $this->createResolver(schema: $entry->schema),
			'description' => 'Create a new ' . $title,
		];
	}//end createField()

	/**
	 * The field that updates an object.
	 *
	 * @param SchemaEntry $entry The schema
	 * @param string $title The title used in the description
	 *
	 * @return array<string, mixed> The field
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function updateField(SchemaEntry $entry, string $title): array {
		$input = $this->typeMapper->getUpdateInputType(schema: $entry->schema);

		return [
			'type' => $entry->objectType,
			'args' => [
				'id' => Type::nonNull(Type::id()),
				'input' => Type::nonNull($input),
			],
			'resolve' => $this->updateResolver(schema: $entry->schema),
			'description' => 'Update an existing ' . $title,
		];
	}//end updateField()

	/**
	 * The field that deletes an object.
	 *
	 * @param SchemaEntry $entry The schema
	 * @param string $title The title used in the description
	 *
	 * @return array<string, mixed> The field
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function deleteField(SchemaEntry $entry, string $title): array {
		return [
			'type' => Type::boolean(),
			'args' => ['id' => Type::nonNull(Type::id())],
			'resolve' => $this->deleteResolver(schema: $entry->schema),
			'description' => 'Delete a ' . $title,
		];
	}//end deleteField()

	/**
	 * Create a resolver for single object queries.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return callable The resolver function
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function singleResolver(RegisterSchema $schema): callable {
		return fn ($root, $args) => $this->resolver?->resolveSingle($schema, $args);
	}//end singleResolver()

	/**
	 * Create a resolver for list queries.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return callable The resolver function
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function listResolver(RegisterSchema $schema): callable {
		return fn ($root, $args) => $this->resolver?->resolveList($schema, $args);
	}//end listResolver()

	/**
	 * Create a resolver for the create mutation.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return callable The resolver function
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function createResolver(RegisterSchema $schema): callable {
		return fn ($root, $args) => $this->resolver?->resolveCreate($schema, $args);
	}//end createResolver()

	/**
	 * Create a resolver for the update mutation.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return callable The resolver function
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function updateResolver(RegisterSchema $schema): callable {
		return fn ($root, $args) => $this->resolver?->resolveUpdate($schema, $args);
	}//end updateResolver()

	/**
	 * Create a resolver for the delete mutation.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return callable The resolver function
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function deleteResolver(RegisterSchema $schema): callable {
		return fn ($root, $args) => $this->resolver?->resolveDelete($schema, $args);
	}//end deleteResolver()
}//end class
