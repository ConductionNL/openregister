<?php

declare(strict_types=1);

/**
 * Destruction-list creation tests for DestructionCheckJob.
 *
 * Pins what the daily check does once objects are eligible: the destruction
 * list is saved as a register object into the configured register and schema,
 * with RBAC and multitenancy off, persisted and silent, and the archivist group
 * is told about the saved list. The saver is resolved from the container; the
 * container answers the concrete save handler and its writer contract with the
 * same instance, exactly as the app container's alias does.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\DestructionCheckJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests that an eligible set becomes one saved destruction list and one review notice.
 */
class DestructionCheckJobListCreationTest extends TestCase {

	/**
	 * The arguments every saveObject() call received.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saves = [];

	/**
	 * The subjects of the notifications sent.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $subjects = [];

	/**
	 * The messages logged at error level.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * An eligible set is saved as one destruction list and announced to the archivists.
	 *
	 * @return void
	 */
	public function testAnEligibleSetIsSavedAsOneListAndAnnounced(): void {
		$listData = ['title' => 'Destruction list', 'objects' => ['uuid-1', 'uuid-2']];

		$this->runJob(eligible: [$this->createMock(ObjectEntity::class), $this->createMock(ObjectEntity::class)], listData: $listData);

		$this->assertSame([], $this->errors);
		$this->assertSame(
			[['reg-7', 'schema-9', $listData, null, false, false, true, true]],
			$this->saves
		);
		$this->assertSame(
			[['destruction_list_review', ['listUuid' => 'list-uuid-1', 'objectCount' => 2]]],
			$this->subjects
		);
	}//end testAnEligibleSetIsSavedAsOneListAndAnnounced()

	/**
	 * Nothing eligible saves nothing and announces nothing.
	 *
	 * @return void
	 */
	public function testNothingEligibleSavesNothing(): void {
		$this->runJob(eligible: [], listData: ['title' => 'unused']);

		$this->assertSame([], $this->errors);
		$this->assertSame([], $this->saves);
		$this->assertSame([], $this->subjects);
	}//end testNothingEligibleSavesNothing()

	/**
	 * A list the retention service could not build saves nothing.
	 *
	 * @return void
	 */
	public function testAListThatCouldNotBeBuiltSavesNothing(): void {
		$this->runJob(eligible: [$this->createMock(ObjectEntity::class)], listData: null);

		$this->assertSame([], $this->saves);
		$this->assertSame([], $this->subjects);
	}//end testAListThatCouldNotBeBuiltSavesNothing()

	/**
	 * Run the job's run() over a stubbed container.
	 *
	 * @param array<int, ObjectEntity> $eligible The objects the retention service finds eligible.
	 * @param array|null               $listData The list the retention service builds.
	 *
	 * @return void
	 */
	private function runJob(array $eligible, ?array $listData): void {
		$this->saves    = [];
		$this->subjects = [];
		$this->errors   = [];

		$container = $this->container(eligible: $eligible, listData: $listData);
		$job       = new DestructionCheckJob($this->createMock(ITimeFactory::class), $container);

		$method = (new ReflectionClass(DestructionCheckJob::class))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * The stubbed app container.
	 *
	 * @param array<int, ObjectEntity> $eligible The objects the retention service finds eligible.
	 * @param array|null               $listData The list the retention service builds.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $eligible, ?array $listData): ContainerInterface {
		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->method('scanObjectsWithRetention')->willReturn(['objects' => [], 'scanned' => 0, 'truncated' => false]);
		$retention->method('getObjectsOnPendingDestructionLists')->willReturn([]);
		$retention->method('findEligibleForDestruction')->willReturn($eligible);
		$retention->method('createDestructionList')->willReturn($listData);

		$settings = $this->createMock(ArchivalSettingsReaderInterface::class);
		$settings->method('getArchivalSettingsOnly')->willReturn(
			['destructionListRegister' => 'reg-7', 'destructionListSchema' => 'schema-9', 'notificationLeadDays' => 30]
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('[]');

		$saved = new ObjectEntity();
		$saved->setUuid('list-uuid-1');
		$saver = $this->createMock(SaveObjectWriterInterface::class);
		$saver->method('saveObject')->willReturnCallback(
			function (mixed ...$args) use ($saved): ObjectEntity {
				// A mock fills in the defaulted trailing parameters; the job passes eight.
				$this->saves[] = array_slice($args, 0, 8);
				return $saved;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		$services = [
			LoggerInterface::class                 => $logger,
			RetentionServiceInterface::class       => $retention,
			ArchivalSettingsReaderInterface::class => $settings,
			IAppConfig::class                      => $appConfig,
			SaveObject::class                      => $saver,
			SaveObjectWriterInterface::class       => $saver,
			INotificationManager::class            => $this->notifications(),
			IGroupManager::class                   => $this->archivists(),
		];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $services[$id]
		);
		return $container;
	}//end container()

	/**
	 * A notification manager that records each notification's subject.
	 *
	 * @return INotificationManager
	 */
	private function notifications(): INotificationManager {
		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $setter) {
					$notification->method($setter)->willReturnSelf();
				}

				$notification->method('setSubject')->willReturnCallback(
					function (string $subject, array $parameters) use ($notification): INotification {
						$this->subjects[] = [$subject, $parameters];
						return $notification;
					}
				);
				return $notification;
			}
		);
		return $manager;
	}//end notifications()

	/**
	 * A group manager whose archivist group holds one user.
	 *
	 * @return IGroupManager
	 */
	private function archivists(): IGroupManager {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivist');

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$user]);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			static fn (string $gid): ?IGroup => $gid === 'archivaris' ? $group : null
		);
		return $groups;
	}//end archivists()
}//end class
