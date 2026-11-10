<?php

/**
 * The installed apps the legacy per-app MCP provider probe walks.
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
 * The app manager and the installed app ids it reported, read once per
 * probe so the cache key and the probe see the same list.
 *
 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
 */
final class InstalledApps {

	/**
	 * Describe the installed apps.
	 *
	 * @param mixed    $appManager The IAppManager instance.
	 * @param string[] $appIds     The installed app ids.
	 *
	 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
	 */
	public function __construct(
		public readonly mixed $appManager,
		public readonly array $appIds,
	) {
	}//end __construct()
}//end class
