<?php

/**
 * OpenRegister Schema Tool
 *
 * LLphant function tool for managing schemas through natural language.
 * Provides CRUD operations on schemas with RBAC and multi-tenancy support.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Mcp\Tool;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Schema Tool
 *
 * Allows agents to manage schemas in OpenRegister.
 * Use this tool when users ask to:
 * - View available schemas
 * - Get details about a specific schema
 * - Create a new schema
 * - Update schema properties
 * - Delete a schema
 *
 * All operations respect user permissions and organization boundaries.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 */
class SchemaTool extends AbstractTool {

	/**
	 * Schema mapper
	 *
	 * @var SchemaMapperInterface
	 */
	private SchemaMapperInterface $schemaMapper;

	/**
	 * Constructor
	 *
	 * @param IUserSession $userSession User session service
	 * @param LoggerInterface $logger Logger service
	 * @param SchemaMapperInterface $schemaMapper Schema mapper
	 * @param SchemaVersioningService|null $schemaVersioning Classifies, versions and logs a definition change (#4102)
	 */
	public function __construct(
		IUserSession $userSession,
		LoggerInterface $logger,
		SchemaMapperInterface $schemaMapper,
		private readonly ?SchemaVersioningService $schemaVersioning = null,
	) {
		parent::__construct(userSession: $userSession, logger: $logger);
		$this->schemaMapper = $schemaMapper;
	}//end __construct()

	/**
	 * Get tool name
	 *
	 * @return string Tool name
	 *
	 * @psalm-return 'schema'
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getName(): string {
		return 'schema';
	}//end getName()

	/**
	 * Get tool description
	 *
	 * @return string The tool description
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getDescription(): string {
		$description = 'Manage schemas: list, view, create, update, or delete schemas. ';
		$description .= 'Schemas define structure and validation rules.';
		return $description;
	}//end getDescription()

	/**
	 * The function definitions, one row each: [name, subject, action,
	 * description, [parameter => [type, description]], required], expanded
	 * by {@see ToolFunctionTable::expand()}.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	/**
	 * The setter of each field update_schema may change, in the order they are applied.
	 *
	 * @var array<string, string>
	 */
	private const UPDATE_SETTERS = [
		'title' => 'setTitle',
		'description' => 'setDescription',
		'properties' => 'setProperties',
		'required' => 'setRequired',
	];

	protected const FUNCTIONS = [
		['list_schemas', 'schema', 'list', 'Get a list of all accessible schemas', [
			'limit' => ['integer', 'Maximum number of schemas to return (default: 100)'],
			'offset' => ['integer', 'Number of schemas to skip for pagination (default: 0)'],
			'register' => ['string', 'Filter schemas by register ID (optional)'],
		], []],
		['get_schema', 'schema', 'get', 'Get details about a specific schema by ID', [
			'id' => ['string', 'The schema ID to retrieve'],
		], ['id']],
		['create_schema', 'schema', 'create', 'Create a new schema with properties definition', [
			'title' => ['string', 'The title of the schema'],
			'description' => ['string', 'A description of what this schema represents'],
			'properties' => ['object', 'JSON Schema properties definition'],
			'required' => ['array', 'Array of required property names'],
		], ['title', 'properties']],
		['update_schema', 'schema', 'update', 'Update an existing schema', [
			'id' => ['string', 'The schema ID to update'],
			'title' => ['string', 'New title for the schema'],
			'description' => ['string', 'New description for the schema'],
			'properties' => ['object', 'Updated JSON Schema properties definition'],
		], ['id']],
		['delete_schema', 'schema', 'delete', 'Delete a schema (only if it has no objects)', [
			'id' => ['string', 'The schema ID to delete'],
		], ['id']],
	];

	/**
	 * Execute a function
	 *
	 * @param string $functionName Function name
	 * @param array $parameters Function parameters
	 * @param string|null $userId User ID for context
	 *
	 * @return array Function result
	 *
	 * @throws \Exception If function execution fails
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array {
		return $this->executeWithUser(functionName: $functionName, parameters: $parameters, userId: $userId);
	}//end executeFunction()

	/**
	 * List schemas
	 *
	 * @param int $limit Result limit
	 * @param int $offset Result offset
	 * @param string|null $register Register filter
	 *
	 * @return (mixed|string|true)[] Result with list of schemas
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Optional nullable filter parameter
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function listSchemas(int $limit = 100, int $offset = 0, ?string $register = null): array {
		$filters = [];
		if ($register !== null) {
			$filters['register'] = $register;
		}

		return $this->listFiltered(filters: $filters, page: ['limit' => $limit, 'offset' => $offset]);
	}//end listSchemas()

	/**
	 * List one page of the schemas matching the filters, with the view filters applied.
	 *
	 * @param array                $filters The filters.
	 * @param array<string, mixed> $page    The `limit` and `offset`.
	 *
	 * @return (mixed|string|true)[] Result with list of schemas
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 */
	private function listFiltered(array $filters, array $page): array {
		$filters = $this->applyViewFilters(params: $filters);

		$schemas = $this->schemaMapper->findAll(limit: $page['limit'], offset: $page['offset'], filters: $filters);

		$schemaList = array_map(
			function ($schema) {
				return $this->summary(schema: $schema);
			},
			$schemas
		);

		return $this->formatSuccess(data: $schemaList, message: sprintf('Found %d schemas', count($schemaList)));
	}//end listFiltered()

	/**
	 * The id, uuid, title, description and version of a schema.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function summary(Schema $schema): array {
		return [
			'id' => $schema->getId(),
			'uuid' => $schema->getUuid(),
			'title' => $schema->getTitle(),
			'description' => $schema->getDescription(),
			'version' => $schema->getVersion(),
		];
	}//end summary()

	/**
	 * The summary of a written schema with its properties, as create and update return it.
	 *
	 * @param Schema $schema The schema.
	 * @param string $message The success message.
	 *
	 * @return (mixed|string|true)[] Result with the schema
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 */
	private function writtenResult(Schema $schema, string $message): array {
		return $this->formatSuccess(
			data: $this->summary(schema: $schema) + ['properties' => $schema->getProperties()],
			message: $message
		);
	}//end writtenResult()

	/**
	 * Get a specific schema
	 *
	 * @param string $id Schema ID
	 *
	 * @return (mixed|string|true)[] Result with schema details
	 *
	 * @throws \Exception If schema not found
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getSchema(string $id): array {
		$schema = $this->schemaMapper->find(id: $id);

		return $this->formatSuccess(
			data: [
				'id' => $schema->getId(),
				'uuid' => $schema->getUuid(),
				'title' => $schema->getTitle(),
				'description' => $schema->getDescription(),
				'version' => $schema->getVersion(),
				'properties' => $schema->getProperties(),
				'required' => $schema->getRequired(),
				'allOf' => $schema->getAllOf(),
				'oneOf' => $schema->getOneOf(),
				'anyOf' => $schema->getAnyOf(),
				'organisation' => $schema->getOrganisation(),
				'created' => $schema->getCreated()?->format('Y-m-d H:i:s'),
				'updated' => $schema->getUpdated()?->format('Y-m-d H:i:s'),
			],
			message: 'Schema retrieved successfully'
		);
	}//end getSchema()

	/**
	 * Create a new schema
	 *
	 * @param string $title Schema title
	 * @param array $properties Schema properties
	 * @param string $description Schema description
	 * @param array|null $required Required properties
	 *
	 * @return (mixed|string|true)[] Result with created schema
	 *
	 * @throws \Exception If creation fails
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function createSchema(string $title, array $properties, string $description = '', ?array $required = null): array {
		return $this->insertSchema(
			data: ['title' => $title, 'description' => $description, 'properties' => $properties],
			required: $required
		);
	}//end createSchema()

	/**
	 * Create a schema from its data, with the required list only when one is given.
	 *
	 * @param array      $data     The title, description and properties.
	 * @param array|null $required Required properties
	 *
	 * @return (mixed|string|true)[] Result with created schema
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 */
	private function insertSchema(array $data, ?array $required): array {
		if ($required !== null) {
			$data['required'] = $required;
		}

		$schema = $this->schemaMapper->createFromArray(object: $data);

		return $this->writtenResult(schema: $schema, message: 'Schema created successfully');
	}//end insertSchema()

	/**
	 * Update an existing schema
	 *
	 * @param string $id Schema ID
	 * @param string|null $title Schema title
	 * @param string|null $description Schema description
	 * @param array|null $properties Schema properties
	 * @param array|null $required Required properties
	 *
	 * @return (mixed|string|true)[] Result with updated schema
	 *
	 * @throws \Exception If update fails
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Optional nullable parameters for partial updates
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function updateSchema(
		string $id,
		?string $title = null,
		?string $description = null,
		?array $properties = null,
		?array $required = null,
	): array {
		return $this->applyUpdate(
			schema: $this->schemaMapper->find(id: $id),
			changes: ['title' => $title, 'description' => $description, 'properties' => $properties, 'required' => $required]
		);
	}//end updateSchema()

	/**
	 * Apply the non-null changes to a stored schema, versioning a definition change.
	 *
	 * @param Schema                    $schema  The stored schema, not yet changed.
	 * @param array<string, mixed|null> $changes The title, description, properties and required list; null keeps one.
	 *
	 * @return (mixed|string|true)[] Result with updated schema
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 */
	private function applyUpdate(Schema $schema, array $changes): array {
		// Classified against the stored definition before it is changed, as
		// every definition update is (#4102).
		$changeSet = $this->classifyAndBump(schema: $schema, changes: $changes);

		foreach (self::UPDATE_SETTERS as $field => $setter) {
			if ($changes[$field] !== null) {
				$schema->$setter($changes[$field]);
			}
		}

		$schema = $this->schemaMapper->update(entity: $schema);

		if ($changeSet !== null) {
			$this->schemaVersioning?->recordChangelog(
				schemaId: (int)$schema->getId(),
				version: $schema->getVersion(),
				changeSet: $changeSet,
				acknowledged: false,
				origin: 'agent tool'
			);
		}

		return $this->writtenResult(schema: $schema, message: 'Schema updated successfully');
	}//end applyUpdate()

	/**
	 * Classify a definition change against the stored schema and bump its version.
	 *
	 * An agent has nobody to answer a breaking-change prompt, so the change is
	 * versioned and recorded, not refused, as on the configuration import.
	 *
	 * @param Schema                    $schema  The stored schema, not yet changed.
	 * @param array<string, mixed|null> $changes The new `properties` and `required`, each null to keep it.
	 *
	 * @return SchemaChangeSet|null The change set, or null when nothing was classified.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	private function classifyAndBump(Schema $schema, array $changes): ?SchemaChangeSet {
		$properties = $changes['properties'];
		$required = $changes['required'];
		if ($this->schemaVersioning === null || ($properties === null && $required === null)) {
			return null;
		}

		$changeSet = $this->schemaVersioning->classify(
			existing: $schema,
			newDefinition: [
				'properties' => ($properties ?? $schema->getProperties() ?? []),
				'required' => ($required ?? $schema->getRequired() ?? []),
			]
		);
		if ($changeSet->hasChanges() === true) {
			$schema->setVersion($this->schemaVersioning->nextVersion(existing: $schema, changeSet: $changeSet));
		}

		return $changeSet;
	}//end classifyAndBump()

	/**
	 * Delete a schema
	 *
	 * **DELETE SAFETY (runtime-schema-api)**: this is an LLM-invokable schema-destruction
	 * surface with no controller in front of it. It deliberately does NOT pass
	 * `force: true` — SchemaMapper::delete() refuses a schema that still holds objects,
	 * and a model acting on an ambiguous instruction must not be able to orphan a user's
	 * data. The ValidationException surfaces to the model as a tool error naming the
	 * object count; deleting the objects is a separate, explicit human decision
	 * (`DELETE /api/schemas/{id}?deleteObjects=true`).
	 *
	 * @param string $id Schema ID
	 *
	 * @return (mixed|string|true)[] Result of deletion
	 *
	 * @throws \Exception If deletion fails
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	public function deleteSchema(string $id): array {
		$schema = $this->schemaMapper->find(id: $id);
		$this->schemaMapper->delete(entity: $schema);

		return $this->formatSuccess(
			data: ['id' => $id],
			message: 'Schema deleted successfully'
		);
	}//end deleteSchema()
}//end class
