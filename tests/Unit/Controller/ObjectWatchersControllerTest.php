<?php

/**
 * ObjectWatchersControllerTest: the follow routes, including the notify switch.
 *
 * Each action resolves the object through ObjectService first and answers 404
 * when it cannot, maps a NotAuthorizedException to 403 and anything else to a
 * logged 500. `PUT .../watch` reads the optional `notify` body parameter, and
 * the follower list never carries anybody's switch.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectWatchersController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Watcher;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectWatchersController
 * @uses \OCA\OpenRegister\Db\Watcher
 * @uses \OCA\OpenRegister\Exception\NotAuthorizedException
 */
class ObjectWatchersControllerTest extends TestCase {

	/**
	 * The watcher service double.
	 *
	 * @var WatcherService&MockObject
	 */
	private WatcherService&MockObject $watchers;

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The user manager double.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->watchers = $this->createMock(WatcherService::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller over a request carrying the given parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool $found Whether the object resolves.
	 *
	 * @return ObjectWatchersController The controller.
	 */
	private function controller(array $params = [], bool $found = true): ObjectWatchersController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		if ($found === true) {
			$this->objects->method('getObject')->willReturn($this->createMock(ObjectEntity::class));
		} else {
			$this->objects->method('getObject')->willThrowException(new RuntimeException('gone'));
		}

		return new ObjectWatchersController(
			'openregister',
			$request,
			$this->objects,
			$this->watchers,
			$this->users,
			$this->logger
		);
	}//end controller()

	/**
	 * A follow row as the mapper would answer it.
	 *
	 * @param string $userId The follower.
	 * @param bool $notify The notifications switch.
	 *
	 * @return Watcher The row.
	 */
	private function row(string $userId, bool $notify = true): Watcher {
		$watcher = new Watcher();
		$watcher->setUserId($userId);
		$watcher->setObjectUuid('uuid-a');
		$watcher->setRegister('r');
		$watcher->setSchema('s');
		$watcher->setNotify($notify);

		return $watcher;
	}//end row()

	/**
	 * No `notify` in the body: the service is told nothing, so a new follow notifies
	 * and an existing one keeps its switch.
	 *
	 * @return void
	 */
	public function testWatchWithoutNotifyLeavesTheSwitchToTheService(): void {
		$this->watchers->expects($this->once())->method('watch')
			->with($this->isInstanceOf(ObjectEntity::class), 'r', 's', null)
			->willReturn($this->row('alice'));

		$response = $this->controller()->watch('r', 's', 'uuid-a');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('alice', $response->getData()['userId']);
		$this->assertTrue($response->getData()['notify']);
	}//end testWatchWithoutNotifyLeavesTheSwitchToTheService()

	/**
	 * `{"notify": false}` reaches the service as false, a string "true" as true,
	 * and an empty string as no switch at all.
	 *
	 * @return void
	 */
	public function testWatchReadsTheNotifySwitch(): void {
		$seen = [];
		$this->watchers->method('watch')->willReturnCallback(
			function (ObjectEntity $object, ?string $register, ?string $schema, ?bool $notify) use (&$seen): Watcher {
				$seen[] = $notify;
				return $this->row('alice', ($notify ?? true));
			}
		);

		$this->controller(['notify' => false])->watch('r', 's', 'uuid-a');
		$this->controller(['notify' => 'true'])->watch('r', 's', 'uuid-a');
		$this->controller(['notify' => ''])->watch('r', 's', 'uuid-a');

		$this->assertSame([false, true, null], $seen);
	}//end testWatchReadsTheNotifySwitch()

	/**
	 * An object that does not resolve is a 404 on every route, and no service call is made.
	 *
	 * @return void
	 */
	public function testAnUnknownObjectIsNotFoundEverywhere(): void {
		$this->watchers->expects($this->never())->method($this->anything());
		$controller = $this->controller([], false);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->watch('r', 's', 'x')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->unwatch('r', 's', 'x')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->index('r', 's', 'x')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->add('r', 's', 'x', 'bob')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->remove('r', 's', 'x', 'bob')->getStatus());
	}//end testAnUnknownObjectIsNotFoundEverywhere()

	/**
	 * A resolved value that is not an object entity is a 404 too.
	 *
	 * @return void
	 */
	public function testANonEntityIsNotFound(): void {
		$request = $this->createMock(IRequest::class);
		$this->objects->method('getObject')->willReturn(null);
		$controller = new ObjectWatchersController('openregister', $request, $this->objects, $this->watchers, $this->users, $this->logger);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->watch('r', 's', 'x')->getStatus());
	}//end testANonEntityIsNotFound()

	/**
	 * A refusal is a 403 with the service's message on every route.
	 *
	 * @return void
	 */
	public function testARefusalIsForbiddenEverywhere(): void {
		$refusal = new NotAuthorizedException('Not yours');
		$this->watchers->method('watch')->willThrowException($refusal);
		$this->watchers->method('unwatch')->willThrowException($refusal);
		$this->watchers->method('listWatchers')->willThrowException($refusal);
		$this->watchers->method('addWatcher')->willThrowException($refusal);
		$this->watchers->method('removeWatcher')->willThrowException($refusal);
		$this->users->method('userExists')->willReturn(true);
		$controller = $this->controller();

		foreach ([
			$controller->watch('r', 's', 'uuid-a'),
			$controller->unwatch('r', 's', 'uuid-a'),
			$controller->index('r', 's', 'uuid-a'),
			$controller->add('r', 's', 'uuid-a', 'bob'),
			$controller->remove('r', 's', 'uuid-a', 'bob'),
		] as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame('Not yours', $response->getData()['message']);
		}
	}//end testARefusalIsForbiddenEverywhere()

	/**
	 * Anything else is a logged 500 that names no internals.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsALoggedServerError(): void {
		$boom = new RuntimeException('database down');
		$this->watchers->method('watch')->willThrowException($boom);
		$this->watchers->method('unwatch')->willThrowException($boom);
		$this->watchers->method('listWatchers')->willThrowException($boom);
		$this->watchers->method('addWatcher')->willThrowException($boom);
		$this->watchers->method('removeWatcher')->willThrowException($boom);
		$this->users->method('userExists')->willReturn(true);
		$this->logger->expects($this->exactly(5))->method('error');
		$controller = $this->controller();

		foreach ([
			$controller->watch('r', 's', 'uuid-a'),
			$controller->unwatch('r', 's', 'uuid-a'),
			$controller->index('r', 's', 'uuid-a'),
			$controller->add('r', 's', 'uuid-a', 'bob'),
			$controller->remove('r', 's', 'uuid-a', 'bob'),
		] as $response) {
			$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
			$this->assertStringNotContainsString('database', $response->getData()['message']);
		}
	}//end testAnUnexpectedFailureIsALoggedServerError()

	/**
	 * Unwatch and remove answer 204.
	 *
	 * @return void
	 */
	public function testUnwatchAndRemoveAnswerNoContent(): void {
		$this->watchers->expects($this->once())->method('unwatch')->willReturn(true);
		$this->watchers->expects($this->once())->method('removeWatcher')
			->with($this->isInstanceOf(ObjectEntity::class), 'bob')->willReturn(true);
		$controller = $this->controller();

		$this->assertSame(Http::STATUS_NO_CONTENT, $controller->unwatch('r', 's', 'uuid-a')->getStatus());
		$this->assertSame(Http::STATUS_NO_CONTENT, $controller->remove('r', 's', 'uuid-a', 'bob')->getStatus());
	}//end testUnwatchAndRemoveAnswerNoContent()

	/**
	 * The list names every follower, quiet ones included, and carries nobody's switch.
	 *
	 * @return void
	 */
	public function testTheListCarriesNobodysSwitch(): void {
		$this->watchers->method('listWatchers')->willReturn([$this->row('alice'), $this->row('bob', false)]);

		$data = $this->controller()->index('r', 's', 'uuid-a')->getData();

		$this->assertSame(2, $data['total']);
		$this->assertSame(['alice', 'bob'], array_column($data['results'], 'userId'));
		foreach ($data['results'] as $result) {
			$this->assertArrayNotHasKey('notify', $result);
		}
	}//end testTheListCarriesNobodysSwitch()

	/**
	 * Adding a colleague: an unknown uid is a 400 and writes nothing; a known one is added.
	 *
	 * @return void
	 */
	public function testAddRefusesAnUnknownUserAndAddsAKnownOne(): void {
		$this->users->method('userExists')->willReturnMap([['ghost', false], ['bob', true]]);
		$this->watchers->expects($this->once())->method('addWatcher')
			->with($this->isInstanceOf(ObjectEntity::class), 'bob', 'r', 's')
			->willReturn($this->row('bob'));
		$controller = $this->controller();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->add('r', 's', 'uuid-a', 'ghost')->getStatus());
		$added = $controller->add('r', 's', 'uuid-a', 'bob');
		$this->assertSame(Http::STATUS_OK, $added->getStatus());
		$this->assertSame('bob', $added->getData()['userId']);
	}//end testAddRefusesAnUnknownUserAndAddsAKnownOne()
}//end class
