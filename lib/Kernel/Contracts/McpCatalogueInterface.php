<?php

/**
 * McpCatalogueInterface: the catalogue of capabilities OpenRegister offers over MCP.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Lists the MCP capabilities and describes one of them. Implemented by McpDiscoveryService.
 */
interface McpCatalogueInterface {

	/**
	 * Get Tier 1 discovery catalog (public, no auth needed)
	 *
	 * Returns a compact overview of all capability areas with drill-down URLs.
	 *
	 * @return array<string, mixed> The discovery catalog
	 */
	public function getCatalog(): array;

	/**
	 * Get the list of valid capability IDs
	 *
	 * @return array<string> List of capability IDs
	 */
	public function getCapabilityIds(): array;

	/**
	 * Get Tier 2 detail for a specific capability (authenticated)
	 *
	 * Returns detailed endpoint documentation and live context data.
	 *
	 * @param string $capability The capability ID
	 *
	 * @return array<string, mixed>|null The capability detail, or null if unknown
	 */
	public function getCapabilityDetail(string $capability): ?array;
}//end interface
