<?php

/**
 * OpenRegister Register Tool
 *
 * LLphant function tool for managing registers through natural language.
 * Provides CRUD operations on registers with RBAC and multi-tenancy support.
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

use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Service\RegisterServiceInterface;

/**
 * Register Tool
 *
 * Allows agents to manage registers in OpenRegister.
 * Use this tool when users ask to:
 * - View available registers
 * - Get details about a specific register
 * - Create a new register
 * - Update register settings
 * - Delete a register
 *
 * All operations respect user permissions and organization boundaries.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 */
class RegisterTool extends AbstractTool {

	/**
	 * Constructor
	 *
	 * @param ToolCallTrail $trail The session that names the caller and the log of each call.
	 * @param RegisterServiceInterface $registerService Register service
	 */
	public function __construct(
		ToolCallTrail $trail,
		private readonly RegisterServiceInterface $registerService,
	) {
		parent::__construct(trail: $trail);
	}//end __construct()

	/**
	 * Get tool name
	 *
	 * @return string Tool name
	 *
	 * @psalm-return 'register'
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getName(): string {
		return 'register';
	}//end getName()

	/**
	 * Get tool description
	 *
	 * @return string The tool description
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getDescription(): string {
		return 'Manage registers: list, view, create, update, or delete registers. Registers organize schemas and objects.';
	}//end getDescription()

	/**
	 * The function definitions, one row each: [name, subject, action,
	 * description, [parameter => [type, description]], required], expanded
	 * by {@see ToolFunctionTable::expand()}.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	protected const FUNCTIONS = [
		['list_registers', 'register', 'list', 'Get a list of all accessible registers', [
			'limit' => ['integer', 'Maximum number of registers to return (default: 100)'],
			'offset' => ['integer', 'Number of registers to skip for pagination (default: 0)'],
		], []],
		['get_register', 'register', 'get', 'Get details about a specific register by ID or slug', [
			'id' => ['string', 'The register ID or slug to retrieve'],
		], ['id']],
		['create_register', 'register', 'create', 'Create a new register', [
			'title' => ['string', 'The title of the register'],
			'description' => ['string', 'A description of what this register is for'],
			'slug' => ['string', 'URL-friendly identifier (optional, generated from title if not provided)'],
		], ['title']],
		['update_register', 'register', 'update', 'Update an existing register', [
			'id' => ['string', 'The register ID to update'],
			'title' => ['string', 'New title for the register'],
			'description' => ['string', 'New description for the register'],
		], ['id']],
		['delete_register', 'register', 'delete', 'Delete a register (only if it has no objects)', [
			'id' => ['string', 'The register ID to delete'],
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
		return $this->executeWithUser(call: new ToolCall(functionName: $functionName, parameters: $parameters), userId: $userId);
	}//end executeFunction()

	/**
	 * List registers
	 *
	 * LLPhant-compatible method that can be called directly.
	 *
	 * @param int $limit Maximum number of registers to return
	 * @param int $offset Offset for pagination
	 *
	 * @return (mixed|string|true)[] Result with list of registers
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function listRegisters(int $limit = 100, int $offset = 0): array {

		$filters = [];
		$filters = $this->applyViewFilters(params: $filters);

		$registers = $this->registerService->findAll(limit: $limit, offset: $offset, filters: $filters);

		$registerList = array_map(
			function ($register) {
				return [
					'id' => $register->getId(),
					'uuid' => $register->getUuid(),
					'title' => $register->getTitle(),
					'description' => $register->getDescription(),
					'slug' => $register->getSlug(),
				];
			},
			$registers
		);

		return $this->formatSuccess(data: $registerList, message: sprintf('Found %d registers', count($registerList)));
	}//end listRegisters()

	/**
	 * Get a specific register
	 *
	 * @param string $id Register ID
	 *
	 * @return (mixed|string|true)[] Result with register details
	 *
	 * @throws \Exception If register not found
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getRegister(string $id): array {
		$register = $this->registerService->find(id: $id);

		return $this->formatSuccess(
			data: [
				'id' => $register->getId(),
				'uuid' => $register->getUuid(),
				'title' => $register->getTitle(),
				'description' => $register->getDescription(),
				'slug' => $register->getSlug(),
				'folder' => $register->getFolder(),
				'organisation' => $register->getOrganisation(),
				'created' => $register->getCreated()?->format('Y-m-d H:i:s'),
				'updated' => $register->getUpdated()?->format('Y-m-d H:i:s'),
			],
			message: 'Register retrieved successfully'
		);
	}//end getRegister()

	/**
	 * Create a new register
	 *
	 * @param CreateRegisterCommand $command The register's title, optional description and slug
	 *
	 * @return (mixed|string|true)[] Result with created register
	 *
	 * @throws \Exception If creation fails
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function createRegister(CreateRegisterCommand $command): array {
		return $this->createFrom(data: ['title' => $command->title, 'description' => $command->description], slug: $command->slug);
	}//end createRegister()

	/**
	 * Create a register from its title and description, with the slug only when one is given.
	 *
	 * @param array<string, string> $data The title and description.
	 * @param string|null           $slug Register slug
	 *
	 * @return (mixed|string|true)[] Result with created register
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 */
	private function createFrom(array $data, ?string $slug): array {
		if ($slug !== null) {
			$data['slug'] = $slug;
		}

		$register = $this->registerService->createFromArray(data: $data);

		return $this->formatSuccess(data: $this->summary(register: $register), message: 'Register created successfully');
	}//end createFrom()

	/**
	 * The id, uuid, title, description and slug of a written register.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function summary(Register $register): array {
		return [
			'id' => $register->getId(),
			'uuid' => $register->getUuid(),
			'title' => $register->getTitle(),
			'description' => $register->getDescription(),
			'slug' => $register->getSlug(),
		];
	}//end summary()


	/**
	 * Update an existing register
	 *
	 * @param UpdateRegisterCommand $command The register's id and the fields to change; null keeps one
	 *
	 * @return (mixed|string|true)[] Result with updated register
	 *
	 * @throws \Exception If update fails
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function updateRegister(UpdateRegisterCommand $command): array {
		$data = array_filter(
			['title' => $command->title, 'description' => $command->description],
			static function ($value): bool {
				return $value !== null;
			}
		);

		return $this->updateFrom(id: $command->id, data: $data);
	}//end updateRegister()

	/**
	 * Update a register with the given fields, refusing an update that changes nothing.
	 *
	 * @param string               $id   Register ID
	 * @param array<string, string> $data The fields to change.
	 *
	 * @return (mixed|string|true)[] Result with updated register
	 *
	 * @throws InvalidArgumentException If there is nothing to change
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 */
	private function updateFrom(string $id, array $data): array {
		if ($data === []) {
			throw new InvalidArgumentException('No update data provided');
		}

		$register = $this->registerService->updateFromArray(id: (int)$id, data: $data);

		return $this->formatSuccess(data: $this->summary(register: $register), message: 'Register updated successfully');
	}//end updateFrom()

	/**
	 * Delete a register
	 *
	 * @param string $id Register ID
	 *
	 * @return (mixed|string|true)[] Result of deletion
	 *
	 * @throws \Exception If deletion fails
	 *
	 * @psalm-return array{success: true, message: string, data: mixed}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function deleteRegister(string $id): array {
		$register = $this->registerService->find(id: $id);
		$this->registerService->delete(register: $register);

		return $this->formatSuccess(
			data: ['id' => $id],
			message: 'Register deleted successfully'
		);
	}//end deleteRegister()
}//end class
