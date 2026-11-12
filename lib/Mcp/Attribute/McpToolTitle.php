<?php
/**
 * #[McpToolTitle] gives an #[McpTool] method a human-readable title.
 *
 * ```php
 * #[McpTool(scope: 'create')]
 * #[McpToolTitle('Create a task')]
 * public function createTask(string $title): array
 * ```
 *
 * The scanner emits it as the tool descriptor's top-level `title`, the MCP
 * tool field a client shows in place of the name. It is never an entry of
 * `annotations`, where `title` stays reserved. A blank title is no declaration.
 * It is its own attribute because #[McpTool] is already a long flat list of
 * optional fields.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\Attribute
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Attribute;

use Attribute;

/**
 * The human title of an attributed MCP tool.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class McpToolTitle {

	/**
	 * Constructor.
	 *
	 * @param string $title The title a client shows for the tool.
	 */
	public function __construct(
		public readonly string $title,
	) {
	}//end __construct()
}//end class
