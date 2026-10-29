<?php

/**
 * The MCP provider discovery registers McpToolsService and nothing else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\McpProviderDiscovery;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Mcp\McpProviderDiscovery
 */
class McpProviderDiscoveryTest extends TestCase {

	/**
	 * register() registers one service, McpToolsService, and no listeners.
	 *
	 * @return void
	 */
	public function testRegistersMcpToolsServiceOnly(): void {
		$context = new RecordingRegistrationContext();
		(new McpProviderDiscovery())->register(context: $context);

		$this->assertSame(
			expected: ['OCA\OpenRegister\Service\Mcp\McpToolsService'],
			actual: $context->services
		);
		$this->assertCount(expectedCount: 0, haystack: $context->listeners);
	}//end testRegistersMcpToolsServiceOnly()
}//end class
