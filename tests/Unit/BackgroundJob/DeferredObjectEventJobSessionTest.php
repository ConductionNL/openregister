<?php

declare(strict_types=1);

/**
 * Pins the acting-user session of DeferredObjectEventJob: set for the
 * dispatch when the captured user resolves, always cleared afterwards, and
 * never touched when no user resolves.
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

use OCA\OpenRegister\BackgroundJob\DeferredObjectEventJob;
use OCA\OpenRegister\BackgroundJob\Support\DeferredObjectEventActor;
use OCA\OpenRegister\BackgroundJob\Support\DeferredObjectEventLookup;
use OCA\OpenRegister\BackgroundJob\Support\DeferredObjectEventPlatform;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The acting-user session around one deferred created event.
 */
class DeferredObjectEventJobSessionTest extends TestCase {

	/**
	 * Session and lookup calls, in order.
	 *
	 * @var array<int, string>
	 */
	private array $trail = [];

	public function testAResolvedUserActsForTheDispatchAndIsClearedAfter(): void {
		$this->runJob(uid: 'anna', findFails: false);

		$this->assertSame(['user anna', 'setUser anna', 'find u-1', 'setUser null'], $this->trail);
	}//end testAResolvedUserActsForTheDispatchAndIsClearedAfter()

	public function testTheSessionIsClearedEvenWhenTheDispatchFails(): void {
		$this->runJob(uid: 'anna', findFails: true);

		$this->assertSame(['user anna', 'setUser anna', 'find u-1', 'setUser null'], $this->trail);
	}//end testTheSessionIsClearedEvenWhenTheDispatchFails()

	public function testAnUnresolvedUserLeavesTheSessionAlone(): void {
		$this->runJob(uid: 'ghost', findFails: false);

		$this->assertSame(['user ghost', 'find u-1'], $this->trail);
	}//end testAnUnresolvedUserLeavesTheSessionAlone()

	public function testNoCapturedUserLeavesTheSessionAlone(): void {
		$this->runJob(uid: '', findFails: false);

		$this->assertSame(['find u-1'], $this->trail);
	}//end testNoCapturedUserLeavesTheSessionAlone()

	/**
	 * Run the job for object u-1 as the given captured user.
	 *
	 * @param string $uid       The captured user id; "anna" resolves.
	 * @param bool   $findFails Whether the object read throws.
	 *
	 * @return void
	 */
	private function runJob(string $uid, bool $findFails): void {
		$this->trail = [];

		$anna = $this->createMock(IUser::class);
		$anna->method('getUID')->willReturn('anna');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $id) use ($anna): ?IUser {
			$this->trail[] = 'user ' . $id;
			return ($id === 'anna') ? $anna : null;
		});
		$session = $this->createMock(IUserSession::class);
		$session->method('setUser')->willReturnCallback(function (?IUser $user): void {
			$this->trail[] = 'setUser ' . ($user?->getUID() ?? 'null');
		});

		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->method('find')->willReturnCallback(function ($identifier) use ($findFails): ObjectEntity {
			$this->trail[] = 'find ' . $identifier;
			if ($findFails === true) {
				throw new RuntimeException('gone');
			}

			return new ObjectEntity();
		});

		$job = new DeferredObjectEventJob(
			$this->createMock(ITimeFactory::class),
			new DeferredObjectEventLookup($objects, $this->createMock(RegisterMapperInterface::class), $this->createMock(SchemaMapperInterface::class)),
			new DeferredObjectEventActor($session, $users),
			new DeferredObjectEventPlatform($this->createMock(IEventDispatcher::class), new NullLogger())
		);

		$method = new \ReflectionMethod(DeferredObjectEventJob::class, 'run');
		$method->setAccessible(true);
		$method->invoke($job, ['uuid' => 'u-1', 'user' => $uid]);
	}//end runJob()
}//end class
