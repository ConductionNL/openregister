<?php

/**
 * The configuration-update notices take the Kernel configuration contract, not the Configuration entity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Kernel\Contracts\ConfigurationInterface;
use OCA\OpenRegister\Service\NotificationService;
use OCA\OpenRegister\Service\NotificationServiceInterface;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The Notifications interface names only Kernel types; the entity still passes and renders the same notice.
 *
 * @covers \OCA\OpenRegister\Service\NotificationService
 */
final class NotificationServiceConfigurationContractTest extends TestCase {

	public function testBothMethodsTakeTheKernelContract(): void {
		foreach ([NotificationServiceInterface::class, NotificationService::class] as $class) {
			foreach (['notifyConfigurationUpdate', 'markConfigurationUpdated'] as $method) {
				$type = (new ReflectionMethod($class, $method))->getParameters()[0]->getType();
				$this->assertInstanceOf(ReflectionNamedType::class, $type);
				$this->assertSame(ConfigurationInterface::class, $type->getName(), $class . '::' . $method);
			}
		}
	}

	public function testTheConfigurationEntityIsTheContract(): void {
		$this->assertTrue(is_subclass_of(Configuration::class, ConfigurationInterface::class));
	}

	public function testAnEntityStillGetsTheSameNotice(): void {
		$configuration = new Configuration();
		$configuration->setTitle('Zaken');
		$configuration->setNotificationGroups(['editors']);
		$configuration->setLocalVersion('1.0.0');
		$configuration->setRemoteVersion('1.1.0');
		$configuration->setId(7);

		$subjects = [];
		$users    = [];
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setDateTime', 'setObject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$notification->method('setUser')->willReturnCallback(
			function (string $user) use (&$users, $notification) {
				$users[] = $user;
				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters) use (&$subjects, $notification) {
				$subjects[] = [$subject, $parameters];
				return $notification;
			}
		);

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects($this->exactly(2))->method('notify');

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			fn (string $groupId): IGroup => $this->groupWith(userId: $groupId . '-user')
		);

		$service = new NotificationService($manager, $groups, $this->createMock(LoggerInterface::class));

		$this->assertSame(2, $service->notifyConfigurationUpdate(configuration: $configuration));
		$this->assertSame(['editors-user', 'admin-user'], $users);
		$this->assertSame('configuration_update_available', $subjects[0][0]);
		$this->assertSame(
			['configurationTitle' => 'Zaken', 'configurationId' => 7, 'currentVersion' => '1.0.0', 'newVersion' => '1.1.0'],
			$subjects[0][1]
		);
	}

	/**
	 * A group with one member.
	 *
	 * @param string $userId The member's id.
	 *
	 * @return IGroup
	 */
	private function groupWith(string $userId): IGroup {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$user]);
		return $group;
	}
}//end class
