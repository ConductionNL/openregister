<?php

/**
 * Behaviour tests for ObjectWatchersController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed fixture properties are self-describing.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Controller\ObjectWatchersController;
use OCA\OpenRegister\Notifications\Controller\Support\ObjectWatchersControllerServices;
use OCA\OpenRegister\Notifications\Db\Watcher;
use OCA\OpenRegister\Service\Interaction\AddressedObject;
use OCA\OpenRegister\Service\Interaction\WatcherServiceInterface;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(ObjectWatchersController::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(NotAuthorizedException::class)]
#[UsesClass(ObjectWatchersControllerServices::class)]
#[UsesClass(Watcher::class)]
class ObjectWatchersControllerBehaviourTest extends TestCase {

	private ObjectOperationsInterface&MockObject $objects;

	private WatcherServiceInterface&MockObject $watchers;

	private IUserManager&MockObject $users;

	private LoggerInterface&MockObject $logger;

	private ObjectEntity $object;

	private mixed $notify = null;

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->watchers = $this->createMock(WatcherServiceInterface::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->object = new ObjectEntity();
		$this->object->setUuid('uuid-a');
	}

	private function controller(): ObjectWatchersController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key): mixed => $key === 'notify' ? $this->notify : null);
		return new ObjectWatchersController(
			$request,
			new ObjectWatchersControllerServices($this->objects, $this->watchers, $this->users, $this->logger)
		);
	}

	private function found(): void {
		$this->objects->expects($this->once())->method('setRegister')->with('reg')->willReturnSelf();
		$this->objects->expects($this->once())->method('setSchema')->with('sch')->willReturnSelf();
		$this->objects->expects($this->once())->method('setObject')->with('id-1')->willReturnSelf();
		$this->objects->method('getObject')->willReturn($this->object);
	}

	private static function row(string $userId, bool $notify): Watcher {
		$watcher = new Watcher();
		$watcher->setUserId($userId);
		$watcher->setObjectUuid('uuid-a');
		$watcher->setNotify($notify);
		return $watcher;
	}

	/**
	 * Every route through the object lookup.
	 *
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function routes(): array {
		return [
			'watch'   => ['watch', ['reg', 'sch', 'id-1']],
			'unwatch' => ['unwatch', ['reg', 'sch', 'id-1']],
			'index'   => ['index', ['reg', 'sch', 'id-1']],
			'add'     => ['add', ['reg', 'sch', 'id-1', 'bob']],
			'remove'  => ['remove', ['reg', 'sch', 'id-1', 'bob']],
		];
	}

	#[DataProvider('routes')]
	public function testUnknownObjectIs404(string $action, array $args): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('setObject')->willThrowException(new RuntimeException('no such object'));
		$this->watchers->expects($this->never())->method($this->anything());
		$response = $this->controller()->$action(...$args);
		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['message' => 'Object not found'], $response->getData());
	}

	#[DataProvider('routes')]
	public function testNullObjectIs404(string $action, array $args): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('setObject')->willReturnSelf();
		$this->objects->method('getObject')->willReturn(null);
		$this->watchers->expects($this->never())->method($this->anything());
		$this->assertSame(404, $this->controller()->$action(...$args)->getStatus());
	}

	/**
	 * Service method each route delegates to.
	 *
	 * @return array<string, array{0: string, 1: array<int, string>, 2: string, 3: string}>
	 */
	public static function failures(): array {
		return [
			'watch'   => ['watch', ['reg', 'sch', 'id-1'], 'watch', 'watch'],
			'unwatch' => ['unwatch', ['reg', 'sch', 'id-1'], 'unwatch', 'unwatch'],
			'index'   => ['index', ['reg', 'sch', 'id-1'], 'listWatchers', 'index'],
			'add'     => ['add', ['reg', 'sch', 'id-1', 'bob'], 'addWatcher', 'add'],
			'remove'  => ['remove', ['reg', 'sch', 'id-1', 'bob'], 'removeWatcher', 'remove'],
		];
	}

	#[DataProvider('failures')]
	public function testNotAuthorizedIs403WithItsMessage(string $action, array $args, string $method, string $context): void {
		$this->found();
		$this->users->method('userExists')->willReturn(true);
		$this->watchers->method($method)->willThrowException(new NotAuthorizedException('may not ' . $context));
		$this->logger->expects($this->never())->method('error');
		$response = $this->controller()->$action(...$args);
		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['message' => 'may not ' . $context], $response->getData());
	}

	#[DataProvider('failures')]
	public function testUnexpectedFailureIsLoggedAnd500(string $action, array $args, string $method, string $context): void {
		$this->found();
		$this->users->method('userExists')->willReturn(true);
		$this->watchers->method($method)->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error')->with(
			'[ObjectWatchersController] ' . $context . ': boom',
			$this->callback(static fn (array $ctx): bool => $ctx['exception'] instanceof RuntimeException)
		);
		$response = $this->controller()->$action(...$args);
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['message' => 'Could not complete the watcher request'], $response->getData());
	}

	/**
	 * Raw notify values and how they reach the service.
	 *
	 * @return array<string, array{0: mixed, 1: ?bool}>
	 */
	public static function notifyValues(): array {
		return [
			'absent'  => [null, null],
			'empty'   => ['', null],
			'true'    => ['true', true],
			'false'   => ['0', false],
			'garbage' => ['maybe', null],
		];
	}

	#[DataProvider('notifyValues')]
	public function testWatchPassesNotifyAndAnswersTheRow(mixed $raw, ?bool $expected): void {
		$this->notify = $raw;
		$this->found();
		$this->watchers->expects($this->once())->method('watch')
			->with(new AddressedObject(object: $this->object, register: 'reg', schema: 'sch'), $expected)
			->willReturn(self::row('alice', true));
		$response = $this->controller()->watch('reg', 'sch', 'id-1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('alice', $response->getData()['userId']);
		$this->assertTrue($response->getData()['notify']);
	}

	public function testUnwatchAnswers204(): void {
		$this->found();
		$this->watchers->expects($this->once())->method('unwatch')->with($this->object)->willReturn(true);
		$response = $this->controller()->unwatch('reg', 'sch', 'id-1');
		$this->assertSame(204, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testIndexListsWatchersWithoutTheirNotifySwitch(): void {
		$this->found();
		$this->watchers->expects($this->once())->method('listWatchers')->with($this->object)
			->willReturn([self::row('alice', false), self::row('bob', true)]);
		$data = $this->controller()->index('reg', 'sch', 'id-1')->getData();
		$this->assertSame(2, $data['total']);
		$this->assertSame(['alice', 'bob'], array_column($data['results'], 'userId'));
		$this->assertArrayNotHasKey('notify', $data['results'][0]);
		$this->assertArrayNotHasKey('notify', $data['results'][1]);
	}

	public function testIndexWithNoWatchersAnswersEmptyList(): void {
		$this->found();
		$this->watchers->method('listWatchers')->willReturn([]);
		$this->assertSame(['results' => [], 'total' => 0], $this->controller()->index('reg', 'sch', 'id-1')->getData());
	}

	public function testAddRefusesUnknownUserWith400(): void {
		$this->found();
		$this->users->expects($this->once())->method('userExists')->with('ghost')->willReturn(false);
		$this->watchers->expects($this->never())->method('addWatcher');
		$response = $this->controller()->add('reg', 'sch', 'id-1', 'ghost');
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'Unknown user'], $response->getData());
	}

	public function testAddKnownUserAnswersTheAddedRow(): void {
		$this->found();
		$this->users->method('userExists')->willReturn(true);
		$this->watchers->expects($this->once())->method('addWatcher')->with(new AddressedObject(object: $this->object, register: 'reg', schema: 'sch'), 'bob')
			->willReturn(self::row('bob', true));
		$response = $this->controller()->add('reg', 'sch', 'id-1', 'bob');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('bob', $response->getData()['userId']);
	}

	public function testRemoveAnswers204(): void {
		$this->found();
		$this->watchers->expects($this->once())->method('removeWatcher')->with($this->object, 'bob')->willReturn(true);
		$this->assertSame(204, $this->controller()->remove('reg', 'sch', 'id-1', 'bob')->getStatus());
	}
}
