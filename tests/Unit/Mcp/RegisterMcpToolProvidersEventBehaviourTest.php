<?php

/**
 * The MCP provider registration event collects providers in arrival order.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Mcp\RegisterMcpToolProvidersEvent;
use PHPUnit\Framework\TestCase;

/**
 * Starts empty; every registered provider is kept, duplicates included, in order.
 */
class RegisterMcpToolProvidersEventBehaviourTest extends TestCase {
	public function testStartsWithNoProviders(): void {
		$this->assertSame([], (new RegisterMcpToolProvidersEvent())->getProviders());
	}

	public function testKeepsEveryProviderInRegistrationOrder(): void {
		$first  = $this->createMock(IMcpToolProvider::class);
		$second = $this->createMock(IMcpToolProvider::class);
		$event  = new RegisterMcpToolProvidersEvent();

		$event->registerProvider($first);
		$event->registerProvider($second);
		$event->registerProvider($first);

		$this->assertSame([$first, $second, $first], $event->getProviders());
	}
}
