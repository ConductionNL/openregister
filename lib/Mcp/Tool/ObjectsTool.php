<?php

/**
 * OpenRegister Objects Tool
 *
 * LLphant function tool for managing objects through natural language.
 * Provides CRUD operations on objects with RBAC and multi-tenancy support.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Objects Tool
 *
 * Allows agents to manage objects in OpenRegister.
 * Use this tool when users ask to:
 * - Search for objects
 * - View object details
 * - Create new objects
 * - Update existing objects
 * - Delete objects
 *
 * All operations respect user permissions and organization boundaries.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 */
class ObjectsTool extends AbstractTool {

	/**
	 * Object service
	 *
	 * @var ObjectOperationsInterface
	 */
	private ObjectOperationsInterface $objectService;

	/**
	 * Constructor
	 *
	 * @param IUserSession $userSession User session service
	 * @param LoggerInterface $logger Logger service
	 * @param ObjectOperationsInterface $objectService Object service
	 */
	public function __construct(
		IUserSession $userSession,
		LoggerInterface $logger,
		ObjectOperationsInterface $objectService,
	) {
		parent::__construct(userSession: $userSession, logger: $logger);
		$this->objectService = $objectService;
	}//end __construct()

	/**
	 * Get tool name
	 *
	 * @return string Tool name
	 *
	 * @psalm-return 'objects'
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getName(): string {
		return 'objects';
	}//end getName()

	/**
	 * Get tool description
	 *
	 * @return string The tool description.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getDescription(): string {
		$description = 'Manage objects: search, view, create, update, or delete objects. ';
		$description .= 'Objects are data records conforming to schemas.';
		return $description;
	}//end getDescription()

	/**
	 * The function definitions, one row each: [name, subject, action,
	 * description, [parameter => [type, description]], required], expanded
	 * by {@see ToolFunctionTable::expand()}.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	protected const FUNCTIONS = [
		['search_objects', 'object', 'search', 'Search for objects with optional filters', [
			'query' => ['string', 'Search query text (optional)'],
			'register' => ['string', 'Filter by register ID (optional)'],
			'schema' => ['string', 'Filter by schema ID (optional)'],
			'limit' => ['integer', 'Maximum number of results (default: 20)'],
			'offset' => ['integer', 'Number of results to skip (default: 0)'],
		], []],
		['get_object', 'object', 'get', 'Get details about a specific object by ID or UUID', [
			'id' => ['string', 'The object ID or UUID to retrieve'],
		], ['id']],
		['create_object', 'object', 'create', 'Create a new object with data', [
			'register' => ['string', 'The register ID where the object should be created'],
			'schema' => ['string', 'The schema ID that defines the object structure'],
			'data' => ['object', 'The object data conforming to the schema'],
		], ['register', 'schema', 'data']],
		['update_object', 'object', 'update', 'Update an existing object', [
			'id' => ['string', 'The object ID to update'],
			'data' => ['object', 'The updated object data (partial updates supported)'],
		], ['id', 'data']],
		['delete_object', 'object', 'delete', 'Delete an object by ID', [
			'id' => ['string', 'The object ID to delete'],
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
	 * Call the function's method, by parameter name when the model sent
	 * named arguments.
	 *
	 * @param string       $functionName The snake_case function name
	 * @param array<mixed> $parameters   The parameters the model sent
	 *
	 * @return array The method's result
	 */
	protected function invokeFunction(string $functionName, array $parameters): array {
		if ($parameters === [] || array_is_list($parameters) === true) {
			return parent::invokeFunction(functionName: $functionName, parameters: $parameters);
		}

		// 🔴 Spread by NAME, not by position. `array_values()` threw away the
		// keys and passed whatever the model happened to list first into
		// whatever parameter happens to be declared first — and
		// `searchObjects()` declares `int $limit` first. So an agent sending
		// the obvious `{query: "..."}` called `searchObjects("...")` and got
		//
		//   Argument #1 ($limit) must be of type int, string given
		//
		// on EVERY call. Measured 2026-08-17: an agent retried four ways (with
		// and without a query, with and without register+schema) and every one
		// failed identically, because the argument it varied was never the one
		// being mis-bound. `search_objects` was unusable for any caller that
		// did not, by luck, order its keys exactly as the signature.
		//
		// Named spread also makes defaults work: an omitted `limit` now IS the
		// declared default instead of shifting every later argument left.
		//
		// Unknown keys are dropped rather than passed, because a named spread
		// throws on a parameter the method does not declare, and a model
		// volunteering an extra hint should not be a fatal.
		$methodName = $this->functionMethod(functionName: $functionName);
		$known      = [];
		foreach ((new ReflectionMethod($this, $methodName))->getParameters() as $parameter) {
			$known[$parameter->getName()] = true;
		}

		return $this->$methodName(...array_intersect_key($parameters, $known));
	}//end invokeFunction()

	/**
	 * Search for objects
	 *
	 * @param int $limit Result limit
	 * @param int $offset Result offset
	 * @param string|null $register Register filter
	 * @param string|null $schema Schema filter
	 * @param string|null $query Search query
	 *
	 * @return (mixed|string|true)[] Result with list of objects
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Optional nullable filter parameters
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function searchObjects(
		int $limit = 20,
		int $offset = 0,
		?string $register = null,
		?string $schema = null,
		?string $query = null,
	): array {
		$filters = [];
		if ($register !== null) {
			$filters['register'] = $register;
		}

		if ($schema !== null) {
			$filters['schema'] = $schema;
		}

		if ($query !== null && $query !== '') {
			$filters['_search'] = $query;
		}

		$filters = $this->applyViewFilters(params: $filters);

		$result = $this->objectService->findAll(
			config: [
				'limit' => $limit,
				'offset' => $offset,
				'filters' => $filters,
			]
		);

		$objectList = array_map(
			function (ObjectEntity $object): array {
				return [
					'id' => $object->getId(),
					'uuid' => $object->getUuid(),
					'register' => $object->getRegister(),
					'schema' => $object->getSchema(),
					'data' => $object->getObject(),
					'created' => $object->getCreated()?->format('Y-m-d H:i:s'),
					'updated' => $object->getUpdated()?->format('Y-m-d H:i:s'),
				];
			},
			$result['results'] ?? []
		);

		return $this->formatSuccess(
			data: [
				'objects' => $objectList,
				'total' => $result['total'] ?? count($objectList),
			],
			message: sprintf('Found %d objects', count($objectList))
		);
	}//end searchObjects()

	/**
	 * Get a specific object
	 *
	 * @param string $id Object ID
	 *
	 * @return (mixed|string|true)[] Result with object details
	 *
	 * @throws \Exception If object not found
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	public function getObject(string $id): array {
		$object = WriteCause::asLookup(fn () => $this->objectService->find(id: $id));
		if ($object === null) {
			throw new RuntimeException("Object with id {$id} not found.");
		}

		return $this->formatSuccess(
			data: [
				'id' => $object->getId(),
				'uuid' => $object->getUuid(),
				'register' => $object->getRegister(),
				'schema' => $object->getSchema(),
				'data' => $object->getObject(),
				'organisation' => $object->getOrganisation(),
				'owner' => $object->getOwner(),
				'created' => $object->getCreated()?->format('Y-m-d H:i:s'),
				'updated' => $object->getUpdated()?->format('Y-m-d H:i:s'),
			],
			message: 'Object retrieved successfully'
		);
	}//end getObject()

	/**
	 * Create a new object
	 *
	 * @param string $register Register identifier
	 * @param string $schema Schema identifier
	 * @param array $data Object data
	 *
	 * @return (mixed|string|true)[] Result with created object
	 *
	 * @throws \Exception If creation fails
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function createObject(string $register, string $schema, array $data): array {
		$objectData = array_merge(
			$data,
			[
				'@self' => [
					'register' => $register,
					'schema' => $schema,
				],
			]
		);

		$object = $this->objectService->saveObject(
			object: $objectData,
			register: (int)$register,
			schema: (int)$schema
		);

		return $this->formatSuccess(
			data: [
				'id' => $object->getId(),
				'uuid' => $object->getUuid(),
				'register' => $object->getRegister(),
				'schema' => $object->getSchema(),
				'data' => $object->getObject(),
			],
			message: 'Object created successfully'
		);
	}//end createObject()

	/**
	 * Update an existing object
	 *
	 * @param string $id Object ID
	 * @param array $data Object data
	 *
	 * @return (mixed|string|true)[] Result with updated object
	 *
	 * @throws \Exception If update fails
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	public function updateObject(string $id, array $data): array {
		// Get existing object.
		$existingObject = WriteCause::asLookup(fn () => $this->objectService->find($id));

		// Merge new data with existing data.
		$mergedData = array_merge(
			$existingObject->getObject(),
			$data
		);

		// Update object.
		$object = $this->objectService->saveObject(
			object: $mergedData,
			register: $existingObject->getRegister(),
			schema: $existingObject->getSchema(),
			uuid: $existingObject->getUuid()
		);

		return $this->formatSuccess(
			data: [
				'id' => $object->getId(),
				'uuid' => $object->getUuid(),
				'register' => $object->getRegister(),
				'schema' => $object->getSchema(),
				'data' => $object->getObject(),
			],
			message: 'Object updated successfully'
		);
	}//end updateObject()

	/**
	 * Delete an object
	 *
	 * @param string $id Object ID
	 *
	 * @return (mixed|string|true)[] Result of deletion
	 *
	 * @throws \Exception If deletion fails
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	public function deleteObject(string $id): array {
		$object = WriteCause::asLookup(fn () => $this->objectService->find(id: $id));
		if ($object === null) {
			throw new RuntimeException("Object with id {$id} not found.");
		}

		$uuid = $object->getUuid() ?? (string)$object->getId();
		$this->objectService->deleteObject(uuid: $uuid);

		return $this->formatSuccess(
			data: ['id' => $id],
			message: 'Object deleted successfully'
		);
	}//end deleteObject()
}//end class
