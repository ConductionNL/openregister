<?php

/**
 * Unit tests for FlowBuiltInToolProviders.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Integrations\BuiltInToolProvidersInterface;
use OCA\OpenRegister\Mcp\BuiltIn\FlowBuiltInToolProviders;
use OCA\OpenRegister\Mcp\BuiltIn\FlowMcpToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\FlowToolServices;
use OCA\OpenRegister\Service\Flow\FlowNodePreflightInterface;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\FlowBuiltInToolProviders
 * @uses   \OCA\OpenRegister\Mcp\BuiltIn\FlowMcpToolProvider
 * @uses   \OCA\OpenRegister\Mcp\BuiltIn\FlowToolServices
 * @uses   \OCA\OpenRegister\Mcp\BuiltIn\AbstractBuiltInToolProvider
 */
final class FlowBuiltInToolProvidersTest extends TestCase {

	/**
	 * The flow tools are the one provider contributed, and the same instance the container built.
	 *
	 * @return void
	 */
	public function testItContributesTheFlowToolProviderOnly(): void {
		$flowTools = new FlowMcpToolProvider(
			new FlowToolServices(
				$this->createMock(FlowRunServiceInterface::class),
				$this->createMock(FlowRunMapperInterface::class),
				$this->createMock(FlowServiceInterface::class),
				$this->createMock(FlowNodePreflightInterface::class),
			),
			$this->createMock(IUserSession::class),
		);

		$contribution = new FlowBuiltInToolProviders(flowTools: $flowTools);

		self::assertInstanceOf(BuiltInToolProvidersInterface::class, $contribution);
		self::assertSame(expected: [$flowTools], actual: $contribution->providers());
	}//end testItContributesTheFlowToolProviderOnly()

	/**
	 * The contributed provider still carries the flow tools under their own ids.
	 *
	 * @return void
	 */
	public function testTheContributedProviderCarriesTheFlowTools(): void {
		$flowTools = new FlowMcpToolProvider(
			new FlowToolServices(
				$this->createMock(FlowRunServiceInterface::class),
				$this->createMock(FlowRunMapperInterface::class),
				$this->createMock(FlowServiceInterface::class),
				$this->createMock(FlowNodePreflightInterface::class),
			),
			$this->createMock(IUserSession::class),
		);

		$ids = array_column((new FlowBuiltInToolProviders(flowTools: $flowTools))->providers()[0]->getTools(), 'id');

		self::assertContains(needle: 'openregister.runFlow', haystack: $ids);
		self::assertContains(needle: 'openregister.flowRunStatus', haystack: $ids);
	}//end testTheContributedProviderCarriesTheFlowTools()
}//end class
