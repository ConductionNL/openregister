<?php

/**
 * OpenRegister TransitionEngine explicit-actor tests
 *
 * A bulk job moves objects in a background worker, where the session may hold
 * no user or another one. TransitionEngine::transition() takes the job's actor
 * explicitly; the move then runs as that actor, so the actor's rights decide
 * the `update` check, and without an actor the session user's do.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/records-bulk-transition/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionProviderRegistry;
use OCA\OpenRegister\Service\Lifecycle\LifecycleWriteBoundary;
use OCA\OpenRegister\Service\Lifecycle\TransitionDispatch;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Lifecycle\TransitionPlatform;
use OCA\OpenRegister\Service\Lifecycle\TransitionSubjects;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The `update` check is the actor's when one is given.
 *
 * The schema here declares no lifecycle, so a move that passes the `update`
 * check stops right after it with "does not declare x-openregister-lifecycle":
 * that message proves the check let the caller through.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\TransitionEngine
 */
class TransitionEngineActorTest extends TestCase {

	/**
	 * The user the session currently holds.
	 *
	 * @var IUser|null
	 */
	private ?IUser $sessionUser = null;

	/**
	 * The users runAs() was asked to act as, in order.
	 *
	 * @var array<int, string>
	 */
	private array $ranAs = [];

	/**
	 * A user with this uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * The engine over a session holding $sessionUid, where only `handler` may update.
	 *
	 * @param string $sessionUid The session user's uid.
	 *
	 * @return TransitionEngine
	 */
	private function engine(string $sessionUid): TransitionEngine {
		$this->sessionUser = $this->user(uid: $sessionUid);
		$this->ranAs       = [];

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->sessionUser);

		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject(['status' => 'ontvangen']);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($object);
		// What ObjectService::runAs() does: the named user is the session user for the call.
		$objects->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation) {
				$this->ranAs[] = $user->getUID();
				$previous = $this->sessionUser;
				$this->sessionUser = $user;
				try {
					return $operation();
				} finally {
					$this->sessionUser = $previous;
				}
			}
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn(new Schema());

		$permission = $this->createMock(PermissionHandler::class);
		$permission->method('hasPermission')->willReturnCallback(
			static fn (Schema $schema, string $action, ?string $userId = null): bool => $action === 'update' && $userId === 'handler'
		);

		return new TransitionEngine(
			new TransitionSubjects($objects, new SchemaCatalog($this->createMock(RegisterMapper::class), $schemas, $this->createMock(MagicMapperInterface::class))),
			new TransitionDispatch($this->createMock(IEventDispatcher::class), new LifecycleWriteBoundary(new LifecycleActionContext()), $this->createMock(LifecycleActionProviderRegistry::class)),
			new TransitionPlatform($session, $this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class)),
			$permission
		);
	}//end engine()

	/**
	 * An actor with `update` moves the object although the session user may not.
	 *
	 * @covers ::transition
	 *
	 * @return void
	 */
	public function testTheActorsRightsLetTheMoveThrough(): void {
		$engine = $this->engine(sessionUid: 'someone-else');

		try {
			$engine->transition('obj-1', 'start', [], $this->user(uid: 'handler'));
			$this->fail('The schema declares no lifecycle, so the move must stop after the update check.');
		} catch (NotAuthorizedException $refused) {
			$this->fail('The actor holds update; the session user must not decide: '.$refused->getMessage());
		} catch (RuntimeException $passed) {
			$this->assertStringContainsString('does not declare x-openregister-lifecycle', $passed->getMessage());
		}

		$this->assertSame(['handler'], $this->ranAs);
		$this->assertSame('someone-else', $this->sessionUser?->getUID(), 'the session user is back afterwards');
	}//end testTheActorsRightsLetTheMoveThrough()

	/**
	 * An actor without `update` is refused although the session user may update.
	 *
	 * @covers ::transition
	 *
	 * @return void
	 */
	public function testAnActorWithoutRightsIsRefusedWhateverTheSession(): void {
		$engine = $this->engine(sessionUid: 'handler');

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('You do not have permission to transition object "obj-1".');

		$engine->transition('obj-1', 'start', [], $this->user(uid: 'clerk'));
	}//end testAnActorWithoutRightsIsRefusedWhateverTheSession()

	/**
	 * Without an actor the session user's rights decide, and nothing runs as anyone else.
	 *
	 * @covers ::transition
	 *
	 * @return void
	 */
	public function testWithoutAnActorTheSessionUserDecides(): void {
		$engine = $this->engine(sessionUid: 'clerk');

		try {
			$engine->transition('obj-1', 'start');
			$this->fail('The clerk may not update.');
		} catch (NotAuthorizedException $refused) {
			$this->assertSame([], $this->ranAs);
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not declare x-openregister-lifecycle');
		$this->engine(sessionUid: 'handler')->transition('obj-1', 'start');
	}//end testWithoutAnActorTheSessionUserDecides()
}//end class
