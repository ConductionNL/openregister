<?php

/**
 * Base of the static built-in MCP tool providers.
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
use OCA\OpenRegister\Mcp\IMcpToolProvider;

/**
 * A built-in provider owned by OpenRegister whose tool descriptors are a
 * class constant. A subclass declares `protected const TOOLS` and its own
 * invokeTool().
 */
abstract class AbstractBuiltInToolProvider implements IMcpToolProvider {

	/**
	 * The tool descriptors; a subclass redeclares it.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected const TOOLS = [];

	/**
	 * Returns the owning app id.
	 *
	 * @return string Always "openregister"
	 */
	public function getAppId(): string {
		return 'openregister';
	}//end getAppId()

	/**
	 * Returns the tool descriptors of the subclass.
	 *
	 * @return list<array{id: string, name: string, description: string, inputSchema: array}>
	 */
	public function getTools(): array {
		return static::TOOLS;
	}//end getTools()

	/**
	 * Throws when a required argument is missing or null.
	 *
	 * @param array<string, mixed> $arguments Tool arguments
	 * @param string               $param     Required parameter name
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the parameter is missing
	 */
	protected function requireParam(array $arguments, string $param): void {
		if (isset($arguments[$param]) === false) {
			throw new InvalidArgumentException('Missing required parameter: ' . $param);
		}
	}//end requireParam()
}//end class
