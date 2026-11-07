<?php

/**
 * Unit tests for NotificationsRegistrar.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications;

use OCA\OpenRegister\Notifications\NotificationsRegistrar;
use OCA\OpenRegister\Notifications\Service\WebPush\HexIconService;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\App\IAppManager;
use OCP\AppFramework\IAppContainer;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Notifications\NotificationsRegistrar
 * @uses   \OCA\OpenRegister\Notifications\Service\WebPush\HexIconService
 */
final class NotificationsRegistrarTest extends TestCase {

	/**
	 * The HexIconService is built on the app's own app data folder.
	 *
	 * @return void
	 */
	public function testTheHexIconServiceGetsTheAppsOwnAppData(): void {
		$context = new RecordingRegistrationContext();
		NotificationsRegistrar::register(context: $context);

		$this->assertSame(expected: [HexIconService::class], actual: $context->services);

		$appData = $this->createMock(IAppData::class);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->expects($this->once())->method('get')->with('openregister')->willReturn($appData);
		$doubles = [
			IAppManager::class => $this->createMock(IAppManager::class),
			IAppDataFactory::class => $factory,
			LoggerInterface::class => $this->createMock(LoggerInterface::class),
		];
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => $doubles[$id]);

		$service = $context->factories[HexIconService::class]($container);

		$this->assertInstanceOf(expected: HexIconService::class, actual: $service);
		$this->assertSame(expected: $appData, actual: (new \ReflectionProperty($service, 'appData'))->getValue($service));
	}//end testTheHexIconServiceGetsTheAppsOwnAppData()

	/**
	 * Every interface is an alias of a class that implements it.
	 *
	 * @return void
	 */
	public function testEveryInterfaceIsBoundToItsImplementation(): void {
		$context = new RecordingRegistrationContext();
		NotificationsRegistrar::register(context: $context);

		$expected = [];
		foreach (NotificationsRegistrar::BINDINGS as $contract => $service) {
			$this->assertTrue(is_subclass_of($service, $contract), $service . ' implements ' . $contract);
			$expected[] = ['alias' => $contract, 'target' => $service];
		}

		$this->assertCount(24, $expected);
		$this->assertSame(expected: $expected, actual: $context->aliases);
	}//end testEveryInterfaceIsBoundToItsImplementation()
}//end class
