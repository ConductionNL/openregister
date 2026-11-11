<?php

/**
 * Built-in schemas MCP tool provider.
 *
 * Exposes CRUD operations on OpenRegister schemas as an MCP tool
 * under the namespaced id `openregister.schemas`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\BuiltIn;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rbac\DefinitionWriteRightsInterface;
use OCA\OpenRegister\Service\Rbac\RbacChecks;
use OCA\OpenRegister\Service\Rbac\RbacRuleChecks;
use RuntimeException;

/**
 * SchemasToolProvider
 *
 * Built-in IMcpToolProvider for schema CRUD operations. All tool logic is
 * relocated from McpToolsService::executeSchemas() into invokeTool().
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @psalm-suppress UnusedClass - Injected via DI container
 */
class SchemasToolProvider extends AbstractBuiltInToolProvider {

	/**
	 * Tool id for the schemas tool
	 */
	public const TOOL_ID = 'openregister.schemas';

	/**
	 * Constructor
	 *
	 * @param SchemaMapperInterface          $schemaMapper Schema database mapper
	 * @param DefinitionWriteRightsInterface $rights       The rights of the signed-in user
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly DefinitionWriteRightsInterface $rights,
	) {
	}//end __construct()

	/**
	 * The tool definitions.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected const TOOLS = [
		[
			'id' => self::TOOL_ID,
			'name' => 'schemas',
			'description' => 'Manage schemas (data definitions that describe the structure of objects)',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					...CrudToolSchema::ACTION,
					'id' => [
						'type' => 'integer',
						'description' => 'Schema ID (required for get, update, delete)',
					],
					'data' => [
						'type' => 'object',
						'description' => 'Schema fields (for create and update)',
					],
					...CrudToolSchema::PAGING,
				],
				'required' => ['action'],
			],
		],
	];

	/**
	 * Invoke the schemas tool.
	 *
	 * @param string $toolId Must be "openregister.schemas"
	 * @param array<string, mixed> $arguments Tool arguments with action, id, data, limit, offset
	 *
	 * @return array<string, mixed> JSON-encodable result
	 *
	 * @throws InvalidArgumentException If action is unknown or required params missing
	 */
	public function invokeTool(string $toolId, array $arguments): array {
		$action = $arguments['action'] ?? null;

		return match ($action) {
			'list' => $this->listSchemas(arguments: $arguments),
			'get' => $this->getSchema(arguments: $arguments),
			'create' => $this->createSchema(arguments: $arguments),
			'update' => $this->updateSchema(arguments: $arguments),
			'delete' => $this->deleteSchema(arguments: $arguments),
			default => throw new InvalidArgumentException('Unknown action: ' . $action),
		};
	}//end invokeTool()

	/**
	 * List schemas.
	 *
	 * @param array<string, mixed> $arguments Contains optional limit and offset
	 *
	 * @return array<int, mixed> List of serialized schemas
	 */
	private function listSchemas(array $arguments): array {
		$limit = $arguments['limit'] ?? null;
		$offset = $arguments['offset'] ?? null;

		// IDOR boundary (IMcpToolProvider contract): SchemaMapper::findAll
		// applies RBAC + multi-tenancy filtering when its $_rbac and
		// $_multitenancy flags are true (the defaults). We pass them
		// explicitly so a service-default refactor cannot silently widen
		// this query across tenants.
		$schemas = $this->schemaMapper->findAll(
			limit: $limit,
			offset: $offset,
			_rbac: true,
			_multitenancy: true
		);

		return array_map(
			callback: static fn ($schema) => $schema->jsonSerialize(),
			array: $schemas
		);
	}//end listSchemas()

	/**
	 * Get a single schema.
	 *
	 * @param array<string, mixed> $arguments Must contain id
	 *
	 * @return array<string, mixed> Serialized schema
	 */
	private function getSchema(array $arguments): array {
		$this->requireParam(arguments: $arguments, param: 'id');
		$schema = $this->schemaMapper->find($arguments['id']);
		return $schema->jsonSerialize();
	}//end getSchema()

	/**
	 * Create a schema.
	 *
	 * @param array<string, mixed> $arguments Must contain data
	 *
	 * @return array<string, mixed> Serialized created schema
	 */
	private function createSchema(array $arguments): array {
		$this->requireParam(arguments: $arguments, param: 'data');
		if ($this->rights->isAdmin() === false) {
			throw new RuntimeException('Only administrators may create schemas');
		}

		$schema = $this->schemaMapper->createFromArray(object: $this->checkedBody(data: $arguments['data'], old: null));
		return $schema->jsonSerialize();
	}//end createSchema()

	/**
	 * Update a schema.
	 *
	 * @param array<string, mixed> $arguments Must contain id and data
	 *
	 * @return array<string, mixed> Serialized updated schema
	 */
	private function updateSchema(array $arguments): array {
		$this->requireParam(arguments: $arguments, param: 'id');
		$this->requireParam(arguments: $arguments, param: 'data');
		$stored = $this->manageableSchema(id: $arguments['id']);
		$this->refuseOrphanCleanupChange(schema: $stored, document: $arguments['data']);
		$data = $this->checkedBody(data: $arguments['data'], old: $stored->getAuthorization());
		unset($data['id'], $data['organisation'], $data['owner'], $data['created']);
		$schema = $this->schemaMapper->updateFromArray(
			id: $arguments['id'],
			object: $data
		);
		return $schema->jsonSerialize();
	}//end updateSchema()

	/**
	 * Refuse a non-administrator's change of `orphanCleanup`, as the schema routes do.
	 *
	 * @param Schema               $schema   The schema as stored, or a new one.
	 * @param array<string, mixed> $document The incoming fields.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the caller may not change the flag.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function refuseOrphanCleanupChange(Schema $schema, array $document): void {
		if (RbacRuleChecks::orphanCleanupFlag()->changedBy(schema: $schema, document: $document) === true && $this->rights->isAdmin() === false) {
			throw new InvalidArgumentException('Only an administrator can change orphanCleanup on a schema');
		}
	}//end refuseOrphanCleanupChange()

	/**
	 * Delete a schema.
	 *
	 * **DELETE SAFETY (runtime-schema-api)**: this is an LLM-invokable schema-destruction
	 * surface with no controller in front of it. It deliberately does NOT pass
	 * `force: true` — SchemaMapper::delete() refuses a schema that still holds objects,
	 * and a model acting on an ambiguous instruction must not be able to orphan a user's
	 * data. The ValidationException surfaces to the model as a tool error naming the
	 * object count; deleting the objects is a separate, explicit human decision
	 * (`DELETE /api/schemas/{id}?deleteObjects=true`).
	 *
	 * @param array<string, mixed> $arguments Must contain id
	 *
	 * @return array<string, mixed> Success message
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	private function deleteSchema(array $arguments): array {
		$this->requireParam(arguments: $arguments, param: 'id');
		$schema = $this->manageableSchema(id: $arguments['id']);
		$this->schemaMapper->delete($schema);
		return ['deleted' => true, 'id' => $arguments['id']];
	}//end deleteSchema()

	/**
	 * The schema with this id, when the signed-in user may manage it.
	 *
	 * @param mixed $id The schema id
	 *
	 * @return Schema The schema
	 *
	 * @throws RuntimeException When the user may not manage it
	 */
	private function manageableSchema(mixed $id): Schema {
		$schema = $this->schemaMapper->find($id);
		if ($this->rights->mayManage(authorization: $schema->getAuthorization()) === false) {
			throw new RuntimeException('User does not have permission to manage this schema');
		}

		return $schema;
	}//end manageableSchema()

	/**
	 * The body to write, after the checks the REST routes make on an authorization.
	 *
	 * @param array<string, mixed> $data The schema body
	 * @param array|null           $old  The stored authorization, null for a new schema
	 *
	 * @return array<string, mixed> The body with its authorization in the form it is stored
	 *
	 * @throws RuntimeException When the block adds a crossing or an external grant without an end
	 */
	private function checkedBody(array $data, ?array $old): array {
		if (array_key_exists('authorization', $data) === false) {
			return $data;
		}

		$stored = $this->storedAuthorization(authorization: $data['authorization'], old: $old);
		if (is_array($stored) === false) {
			return $data;
		}

		$this->refuseOpenEndedExternalGrant(stored: $stored);
		$data['authorization'] = $stored;

		return $data;
	}//end checkedBody()

	/**
	 * The authorization in the form it is stored, refused when a non-administrator widens a crossing.
	 *
	 * @param mixed      $authorization The authorization block as written.
	 * @param array|null $old           The stored authorization, null for a new schema.
	 *
	 * @return mixed The block as stored.
	 *
	 * @throws RuntimeException When the block adds a crossing and the caller is not an administrator
	 */
	private function storedAuthorization(mixed $authorization, ?array $old): mixed {
		$change = RbacRuleChecks::crossOrganisationMarkerChange();
		$stored = $change->asStored(authorization: $authorization);
		if ($change->widens(old: $old, new: $stored) === true && $this->rights->isAdmin() === false) {
			throw new RuntimeException('Only an administrator may let a rule read across organisations (crossOrganisation: true)');
		}

		return $stored;
	}//end storedAuthorization()

	/**
	 * Refuse an external grant that has no end date.
	 *
	 * @param array<string, mixed> $stored The authorization block as stored.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When an external grant has no end
	 */
	private function refuseOpenEndedExternalGrant(array $stored): void {
		if (RbacChecks::externalGrants()->refusalFor($stored) !== null) {
			throw new RuntimeException('An external grant needs an end date');
		}
	}//end refuseOpenEndedExternalGrant()
}//end class
