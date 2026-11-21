<?php

/**
 * The per-app MCP provider discovery map and the cache slot it is stored in.
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

use OCP\ICache;

/**
 * A rebuilt discovery map (appId => winning candidate key, or null) with the
 * distributed cache and key it is written to; the cache is null when no
 * distributed backend exists.
 *
 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
 */
final class McpDiscoveryCacheEntry {

	/**
	 * Describe the entry.
	 *
	 * @param ICache|null                $cache The cache, or null when disabled.
	 * @param string                     $key   The cache key.
	 * @param array<string, string|null> $map   The appId => winning key|null map.
	 *
	 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
	 */
	public function __construct(
		public readonly ?ICache $cache,
		public readonly string $key,
		public readonly array $map,
	) {
	}//end __construct()
}//end class
