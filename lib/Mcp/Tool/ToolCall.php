<?php

/**
 * One function call a model makes on a tool.
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
 * The function a model calls on a tool and the arguments it sent.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ToolCall {
	/**
	 * Constructor.
	 *
	 * @param string       $functionName The snake_case function name.
	 * @param array<mixed> $parameters   The arguments the model sent, named or positional.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $functionName,
		public readonly array $parameters,
	) {
	}//end __construct()
}//end class
