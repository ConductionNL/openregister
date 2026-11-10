<?php

/**
 * The create_agent function of the agent tool.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Mcp\Tool
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Mcp\Tool;

/**
 * A new AI agent: its name and the optional description, type and system prompt.
 *
 * The model's arguments bind to this constructor by name or by position.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class CreateAgentCommand implements ToolOperation {
	/**
	 * Constructor.
	 *
	 * @param string  $name         Agent name
	 * @param string|null$description  Agent description
	 * @param string|null$type         Agent type
	 * @param string|null$systemPrompt Agent system prompt
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?string $description = null,
		public readonly ?string $type = null,
		public readonly ?string $systemPrompt = null,
	) {
	}//end __construct()
}//end class
