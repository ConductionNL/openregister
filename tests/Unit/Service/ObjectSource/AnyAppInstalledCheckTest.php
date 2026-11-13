<?php

/**
 * Whether a provider gated on any of several apps is enabled: one installed
 * app enables it, none leaves it disabled, and a failing app manager
 * disables it rather than throwing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Service\ObjectSource\CalDavVtodoObjectSourceProvider;
use OCA\OpenRegister\Service\UserTaskListInterface;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the AnyAppInstalledCheck trait, through the CalDAV VTODO provider that uses it.
 */
class AnyAppInstalledCheckTest extends TestCase {

	/**
	 * A provider over an app manager answering per app.
	 *
	 * @param IAppManager $apps The app manager.
	 *
	 * @return CalDavVtodoObjectSourceProvider The provider.
	 */
	private function provider(IAppManager $apps): CalDavVtodoObjectSourceProvider {
		return new CalDavVtodoObjectSourceProvider(taskService: $this->createMock(UserTaskListInterface::class), app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $apps, container: $this->createMock(\Psr\Container\ContainerInterface::class), logger: new NullLogger()));
	}//end provider()

	/**
	 * One installed enabling app enables the provider; none disables it.
	 *
	 * @return void
	 */
	public function testOneInstalledAppEnablesIt(): void {
		$some = $this->createMock(IAppManager::class);
		$some->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $app === 'calendar');
		$none = $this->createMock(IAppManager::class);
		$none->method('isInstalled')->willReturn(false);

		$this->assertTrue($this->provider(apps: $some)->isEnabled());
		$this->assertFalse($this->provider(apps: $none)->isEnabled());
	}//end testOneInstalledAppEnablesIt()

	/**
	 * A failing app manager disables the provider.
	 *
	 * @return void
	 */
	public function testAFailingAppManagerDisablesIt(): void {
		$failing = $this->createMock(IAppManager::class);
		$failing->method('isInstalled')->willThrowException(new RuntimeException('app store down'));

		$this->assertFalse($this->provider(apps: $failing)->isEnabled());
	}//end testAFailingAppManagerDisablesIt()
}//end class
