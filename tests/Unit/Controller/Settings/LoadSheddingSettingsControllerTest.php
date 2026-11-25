<?php

/**
 * Unit test for LoadSheddingSettingsController: read, store, refuse.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\LoadSheddingSettingsController;
use OCA\OpenRegister\Service\Resilience\LoadSheddingSettings;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(LoadSheddingSettingsController::class)]
#[UsesClass(LoadSheddingSettings::class)]
class LoadSheddingSettingsControllerTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => $default
		);
		$this->appConfig->method('getValueBool')->willReturn(true);
	}

	private function controller(array $params = []): LoadSheddingSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$settings = new LoadSheddingSettings($this->appConfig, $this->createMock(LoggerInterface::class));
		return new LoadSheddingSettingsController($request, $settings);
	}

	public function testIndexAnswersTheForm(): void {
		$data = $this->controller()->index()->getData();

		$this->assertSame(30, $data['values']['breakerCoolDown']);
		$this->assertTrue($data['values']['pressureEnabled']);
		$this->assertSame(['min' => 1, 'max' => 100], $data['bounds']['pressureSlowPercent']);
	}

	public function testUpdateStoresAndAnswersTheForm(): void {
		$this->appConfig->expects($this->once())->method('setValueInt')
			->with('openregister', 'load_shedding_breaker_min_calls', 20)->willReturn(true);

		$response = $this->controller(['breakerMinCalls' => 20, '_route' => 'openregister.Settings\LoadSheddingSettings.update'])->update();

		$this->assertSame(200, $response->getStatus());
		$this->assertArrayHasKey('values', $response->getData());
	}

	public function testAnOutOfBoundsFieldIs400AndStoresNothing(): void {
		$this->appConfig->expects($this->never())->method('setValueInt');

		$response = $this->controller(['pressureErrorPercent' => 0])->update();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'pressureErrorPercent must be a whole number from 1 to 100.'], $response->getData());
	}

	public function testAFailingStoreIs500WithoutItsMessage(): void {
		$this->appConfig->method('setValueInt')->willThrowException(new RuntimeException('db at 10.0.0.5 down'));

		$response = $this->controller(['breakerCoolDown' => 40])->update();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'The settings could not be stored.'], $response->getData());
	}

	public function testBothMethodsAreAdministratorOnlyAndTheWriteKeepsCsrf(): void {
		foreach (['index', 'update'] as $method) {
			$reflection = new ReflectionMethod(LoadSheddingSettingsController::class, $method);
			$this->assertSame([], $reflection->getAttributes(NoAdminRequired::class), $method);
		}

		$this->assertSame([], (new ReflectionMethod(LoadSheddingSettingsController::class, 'update'))->getAttributes(NoCSRFRequired::class));
	}
}
