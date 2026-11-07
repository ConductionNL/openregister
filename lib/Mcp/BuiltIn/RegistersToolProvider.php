<?php

/**
 * Built-in registers MCP tool provider.
 *
 * Exposes CRUD operations on OpenRegister registers as an MCP tool
 * under the namespaced id `openregister.registers`.
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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Service\Rbac\DefinitionWriteRights;
use OCA\OpenRegister\Service\RegisterServiceInterface;
use RuntimeException;

/**
 * RegistersToolProvider
 *
 * Built-in IMcpToolProvider for register CRUD operations. All tool logic is
 * relocated from McpToolsService::executeRegisters() into invokeTool().
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @psalm-suppress UnusedClass - Injected via DI container
 */
class RegistersToolProvider extends AbstractBuiltInToolProvider {

	/**
	 * Tool id for the registers tool
	 */
	public const TOOL_ID = 'openregister.registers';

	/**
	 * Constructor
	 *
	 * @param RegisterServiceInterface $registerService Register service for CRUD operations
	 * @param DefinitionWriteRights    $rights          The rights of the signed-in user
	 */
	public function __construct(
		private readonly RegisterServiceInterface $registerService,
		private readonly DefinitionWriteRights $rights,
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
			'name' => 'registers',
			'description' => 'Manage registers (data containers that group schemas and objects)',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					...CrudToolSchema::ACTION,
					'id' => [
						'type' => 'integer',
						'description' => 'Register ID (required for get, update, delete)',
					],
					'data' => [
						'type' => 'object',
						'description' => 'Register fields (for create and update)',
					],
					...CrudToolSchema::PAGING,
				],
				'required' => ['action'],
			],
		],
	];

	/**
	 * Invoke the registers tool.
	 *
	 * @param string $toolId Must be "openregister.registers"
	 * @param array<string, mixed> $arguments Tool arguments with action, id, data, limit, offset
	 *
	 * @return array<string, mixed> JSON-encodable result
	 *
	 * @throws InvalidArgumentException If action is unknown or required params missing
	 */
	public function invokeTool(string $toolId, array $arguments): array {
		$action = $arguments['action'] ?? null;

		return match ($action) {
			'list' => $this->listRegisters(arguments: $arguments),
			'get' => $this->getRegister(arguments: $arguments),
			'create' => $this->createRegister(arguments: $arguments),
			'update' => $this->updateRegister(arguments: $arguments),
			'delete' => $this->deleteRegister(arguments: $arguments),
			default => throw new InvalidArgumentException('Unknown action: ' . $action),
		};
	}//end invokeTool()

	/**
	 * List registers.
	 *
	 * @param array<string, mixed> $arguments Contains optional limit and offset
	 *
	 * @return array<int, mixed> List of serialized registers
	 */
	private function listRegisters(array $arguments): array {
		$limit = $arguments['limit'] ?? null;
		$offset = $arguments['offset'] ?? null;

		// IDOR boundary (IMcpToolProvider contract): RegisterServiceInterface::findAll
		// applies RBAC + multi-tenancy filtering by default — the underlying
		// mapper joins on the active organisation and filters by RBAC role.
		// We pass the flags explicitly so a future refactor of the service
		// defaults cannot silently widen this query to all-tenants. Do NOT
		// disable either flag here.
		$registers = $this->registerService->findAll(
			limit: $limit,
			offset: $offset,
			_multitenancy: true
		);

		return array_map(
			callback: static fn ($r) => $r->jsonSerialize(),
			array: $registers
		);
	}//end listRegisters()

	/**
	 * Get a single register.
	 *
	 * @param array<string, mixed> $arguments Must contain id
	 *
	 * @return array<string, mixed> Serialized register
	 */
	private function getRegister(array $arguments): array {
		$this->requireParam(arguments: $arguments, param: 'id');
		$register = $this->registerService->find(id: $arguments['id']);
		return $register->jsonSerialize();
	}//end getRegister()

	/**
	 * Create a register.
	 *
	 * @param array<string, mixed> $arguments Must contain data
	 *
	 * @return array<string, mixed> Serialized created register
	 */
	private function createRegister(array $arguments): array {
		$this->requireParam(arguments: $arguments, param: 'data');
		if ($this->rights->isAdmin() === false) {
			throw new RuntimeException('Only administrators may create registers');
		}

		$register = $this->registerService->createFromArray(data: $arguments['data']);
		return $register->jsonSerialize();
	}//end createRegister()

	/**
	 * Update a register.
	 *
	 * @param array<string, mixed> $arguments Must contain id and data
	 *
	 * @return array<string, mixed> Serialized updated register
	 */
	private function updateRegister(array $arguments): array {
		$this->requireParam(arguments: $arguments, param: 'id');
		$this->requireParam(arguments: $arguments, param: 'data');
		$this->manageableRegister(id: $arguments['id']);
		$data = $arguments['data'];
		unset($data['id'], $data['organisation'], $data['owner'], $data['created']);
		$register = $this->registerService->updateFromArray(
			id: $arguments['id'],
			data: $data
		);
		return $register->jsonSerialize();
	}//end updateRegister()

	/**
	 * Delete a register.
	 *
	 * @param array<string, mixed> $arguments Must contain id
	 *
	 * @return array<string, mixed> Success message
	 */
	private function deleteRegister(array $arguments): array {
		$this->requireParam(arguments: $arguments, param: 'id');
		$register = $this->manageableRegister(id: $arguments['id']);
		$this->registerService->delete(register: $register);
		return ['deleted' => true, 'id' => $arguments['id']];
	}//end deleteRegister()

	/**
	 * The register with this id, when the signed-in user may manage it.
	 *
	 * @param mixed $id The register id
	 *
	 * @return Register The register
	 *
	 * @throws RuntimeException When the user may not manage it
	 */
	private function manageableRegister(mixed $id): Register {
		$register = $this->registerService->find(id: $id);
		if ($this->rights->mayManage(authorization: $register->getAuthorization()) === false) {
			throw new RuntimeException('User does not have permission to manage this register');
		}

		return $register;
	}//end manageableRegister()
}//end class
