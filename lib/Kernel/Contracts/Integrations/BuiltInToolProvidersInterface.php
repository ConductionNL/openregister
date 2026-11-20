<?php
/**
 * BuiltInToolProvidersInterface: the built-in MCP tool providers an area above Integrations contributes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Integrations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/flow-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Integrations;

/**
 * The built-in MCP tool providers that another area of OpenRegister contributes.
 *
 * The MCP discovery (Integrations) asks for them right after its own built-in
 * providers, in the same place of the catalogue: the catalogue is first-wins
 * on tool name, so their position decides collisions. The flow tools (Processes)
 * answer, bound to this contract in the flow registrar.
 *
 * @spec openspec/specs/flow-mcp/spec.md
 */
interface BuiltInToolProvidersInterface {

	/**
	 * The contributed providers, in their fixed order.
	 *
	 * @return list<\OCA\OpenRegister\Mcp\IMcpToolProvider> The providers.
	 *
	 * @spec openspec/specs/flow-mcp/spec.md
	 */
	public function providers(): array;
}//end interface
