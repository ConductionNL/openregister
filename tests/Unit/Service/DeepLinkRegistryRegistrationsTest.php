<?php

/**
 * DeepLinkRegistryService::registrations(): every registration, asking the
 * apps first when none is registered yet.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-capability/specs/deep-link-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Dto\DeepLinkRegistration;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\DeepLinkRegistryService
 * @uses   \OCA\OpenRegister\Dto\DeepLinkRegistration
 * @uses   \OCA\OpenRegister\Event\DeepLinkRegistrationEvent
 */
class DeepLinkRegistryRegistrationsTest extends TestCase {

	protected function setUp(): void {
		DeepLinkRegistryService::reset();
	}//end setUp()

	protected function tearDown(): void {
		DeepLinkRegistryService::reset();
	}//end tearDown()

	/**
	 * A registry over a container whose dispatcher lets one app register.
	 *
	 * @param int $dispatched How many times the apps were asked.
	 *
	 * @return DeepLinkRegistryService
	 */
	private function registry(int &$dispatched): DeepLinkRegistryService {
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use (&$dispatched): void {
				$dispatched++;
				if ($event instanceof DeepLinkRegistrationEvent) {
					$event->getRegistry()->add(
						new DeepLinkRegistration(appId: 'dossiq', registerSlug: 'zaken', schemaSlug: 'zaak', urlTemplate: '/apps/dossiq/#/cases/{uuid}')
					);
				}
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => ($id === IEventDispatcher::class ? $events : null)
		);

		return new DeepLinkRegistryService($container, $this->createMock(LoggerInterface::class));
	}//end registry()

	/**
	 * An empty registry asks the apps once, then lists what they registered under its key.
	 *
	 * @return void
	 */
	public function testAnEmptyRegistryAsksTheAppsOnce(): void {
		$dispatched = 0;
		$registry = $this->registry($dispatched);

		$registrations = $registry->registrations();

		self::assertSame(['zaken::zaak'], array_keys($registrations));
		self::assertSame('dossiq', $registrations['zaken::zaak']->appId);
		self::assertSame('icon-dossiq', $registrations['zaken::zaak']->icon);

		$registry->registrations();
		self::assertSame(1, $dispatched);
	}//end testAnEmptyRegistryAsksTheAppsOnce()

	/**
	 * A registry that holds registrations lists them without asking the apps.
	 *
	 * @return void
	 */
	public function testAFilledRegistryDoesNotAskTheApps(): void {
		$dispatched = 0;
		$registry = $this->registry($dispatched);
		$registry->add(new DeepLinkRegistration(appId: 'pipelinq', registerSlug: 'crm', schemaSlug: 'client', urlTemplate: '/apps/pipelinq/clients/{uuid}'));

		self::assertSame(['crm::client'], array_keys($registry->registrations()));
		self::assertSame(0, $dispatched);
	}//end testAFilledRegistryDoesNotAskTheApps()
}//end class
