<?php

/**
 * The built-in tools an agent reads and writes registers, schemas and objects with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Mcp\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Listener;

use OCA\OpenRegister\Mcp\Tool\ObjectsTool;
use OCA\OpenRegister\Mcp\Tool\RegisterTool;
use OCA\OpenRegister\Mcp\Tool\SchemaTool;

/**
 * A dependency bundle of {@see ToolRegistrationListener}.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
final class BuiltinDataTools {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterTool $registerTool The register tool.
	 * @param SchemaTool   $schemaTool   The schema tool.
	 * @param ObjectsTool  $objectsTool  The objects tool.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function __construct(
		public readonly RegisterTool $registerTool,
		public readonly SchemaTool $schemaTool,
		public readonly ObjectsTool $objectsTool,
	) {
	}//end __construct()
}//end class
