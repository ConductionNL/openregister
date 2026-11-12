<?php

/**
 * The app-state health checks: whether an app is installed, and whether an
 * app-config key exists and, when asserted nonEmpty, holds a value.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Observability;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\AppHost\Observability\AppStateHealthChecks;
use OCA\OpenRegister\AppHost\Observability\HealthCheckDescriptor;
use OCA\OpenRegister\AppHost\Observability\HealthCheckTarget;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * AppStateHealthChecks.
 *
 * @covers \OCA\OpenRegister\AppHost\Observability\AppStateHealthChecks
 * @uses \OCA\OpenRegister\AppHost\Observability\HealthCheckDescriptor
 * @uses \OCA\OpenRegister\AppHost\Observability\HealthCheckTarget
 */
class AppStateHealthChecksTest extends TestCase {

	private function checks(?IAppManager $appManager = null, ?IAppConfig $appConfig = null): AppStateHealthChecks {
		return new AppStateHealthChecks(
			($appManager ?? $this->createMock(IAppManager::class)),
			($appConfig ?? $this->createMock(IAppConfig::class))
		);
	}//end checks()

	private function descriptor(?string $key, ?string $assert = null): HealthCheckDescriptor {
		return new HealthCheckDescriptor('cfg', 'appConfig', 'critical', new HealthCheckTarget(key: $key, assert: $assert));
	}//end descriptor()

	public function testAnInstalledAppPasses(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())->method('isInstalled')->with('calendar')->willReturn(true);

		$this->assertSame([true, ''], $this->checks(appManager: $appManager)->appEnabled('calendar'));
	}//end testAnInstalledAppPasses()

	public function testAMissingAppFails(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);

		$this->assertSame([false, 'app not enabled'], $this->checks(appManager: $appManager)->appEnabled('calendar'));
	}//end testAMissingAppFails()

	public function testAMissingConfigKeyFails(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('hasKey')->with('dossiq', 'api_url')->willReturn(false);
		$appConfig->expects($this->never())->method('getValueString');

		$this->assertSame([false, 'config key missing'], $this->checks(appConfig: $appConfig)->appConfig('dossiq', $this->descriptor('api_url', 'nonEmpty')));
	}//end testAMissingConfigKeyFails()

	public function testAPresentKeyPassesWithoutAnAssertion(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(true);
		$appConfig->expects($this->never())->method('getValueString');

		$this->assertSame([true, ''], $this->checks(appConfig: $appConfig)->appConfig('dossiq', $this->descriptor('api_url')));
	}//end testAPresentKeyPassesWithoutAnAssertion()

	public function testAnEmptyValueFailsTheNonEmptyAssertion(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(true);
		$appConfig->expects($this->once())->method('getValueString')->with('dossiq', 'api_url', '')->willReturn('');

		$this->assertSame([false, 'config key empty'], $this->checks(appConfig: $appConfig)->appConfig('dossiq', $this->descriptor('api_url', 'nonEmpty')));
	}//end testAnEmptyValueFailsTheNonEmptyAssertion()

	public function testAValueSatisfiesTheNonEmptyAssertion(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(true);
		$appConfig->method('getValueString')->willReturn('https://api.example');

		$this->assertSame([true, ''], $this->checks(appConfig: $appConfig)->appConfig('dossiq', $this->descriptor('api_url', 'nonEmpty')));
	}//end testAValueSatisfiesTheNonEmptyAssertion()

	public function testADescriptorWithoutAKeyChecksTheEmptyKey(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('hasKey')->with('dossiq', '')->willReturn(false);

		$this->assertSame([false, 'config key missing'], $this->checks(appConfig: $appConfig)->appConfig('dossiq', $this->descriptor(null)));
	}//end testADescriptorWithoutAKeyChecksTheEmptyKey()
}//end class
