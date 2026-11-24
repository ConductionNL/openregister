<?php

/**
 * Contributes the flow MCP tools to the built-in MCP catalogue.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
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

namespace OCA\OpenRegister\Mcp\BuiltIn;

use OCA\OpenRegister\Kernel\Contracts\Integrations\BuiltInToolProvidersInterface;

/**
 * The flow tools, as the built-in providers the MCP discovery asks for after its own.
 *
 * @spec openspec/specs/flow-mcp/spec.md
 */
final class FlowBuiltInToolProviders implements BuiltInToolProvidersInterface {

	/**
	 * Hold the flow tool provider.
	 *
	 * @param FlowMcpToolProvider $flowTools The flow listing, authoring, run and status tools.
	 *
	 * @spec openspec/specs/flow-mcp/spec.md
	 */
	public function __construct(
		private readonly FlowMcpToolProvider $flowTools,
	) {
	}//end __construct()

	/**
	 * The flow tool provider, the only built-in provider Processes contributes.
	 *
	 * @return list<\OCA\OpenRegister\Mcp\IMcpToolProvider> The providers.
	 *
	 * @spec openspec/specs/flow-mcp/spec.md
	 */
	public function providers(): array {
		return [$this->flowTools];
	}//end providers()
}//end class
