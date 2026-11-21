<?php

/**
 * One candidate lookup key for an app's legacy per-app MCP tool provider.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

/**
 * An app and one key its provider may be registered under (an alias or a
 * FQCN), as PerAppMcpProviderProbe tries them.
 *
 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
 */
final class McpProviderCandidate {

	/**
	 * Describe the candidate.
	 *
	 * @param string $appId The Nextcloud app id.
	 * @param string $key   The candidate lookup key.
	 *
	 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $key,
	) {
	}//end __construct()
}//end class
