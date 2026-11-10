<?php

/**
 * ToolRegistryInterface: what callers outside the Mcp component use of ToolRegistry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Mcp\Tool\ToolInterface;

/**
 * The registry of agent tools, as the agents controller and the tool registration event use it.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
interface ToolRegistryInterface {

	/**
	 * Register a tool.
	 *
	 * @param string        $id       Unique tool identifier (format: app_name.tool_name)
	 * @param ToolInterface $tool     Tool instance
	 * @param array         $metadata Tool metadata (name, description, icon, app)
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If tool ID is invalid or already registered
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function registerTool(string $id, ToolInterface $tool, array $metadata): void;

	/**
	 * Get all registered tools.
	 *
	 * @return array Array of tool IDs and their metadata
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function getAllTools(): array;
}//end interface
