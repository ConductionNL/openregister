<?php

/**
 * Golden test for DeferredObjectEventJob::run().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\DeferredObjectEventJob;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\DraftCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Pins every call run() makes, whatever shape the job's constructor takes:
 * the job is built by reflection from a map of the collaborators below.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\DeferredObjectEventJob
 */
class DeferredObjectEventJobGoldenTest extends TestCase {

	/**
	 * The collaborators, keyed by the type the job asks for.
	 *
	 * @var array<string, object>
	 */
	private array $deps = [];

	/**
	 * Every recorded call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The object the mapper returns, or null to throw.
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $object = null;

	protected function setUp(): void {
		parent::setUp();

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid) use ($alice): ?IUser {
				$this->calls[] = ['getUser', $uid];
				if ($uid === 'alice') {
					return $alice;
				}

				return null;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('setUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->calls[] = ['setUser', $user?->getUID()];
			}
		);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			function (string|int $id, ...$rest): Register {
				$this->calls[] = ['findRegister', $id, $rest];
				$register = new Register();
				$register->setId((int)$id);
				return $register;
			}
		);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id, ...$rest): Schema {
				$this->calls[] = ['findSchema', $id, $rest];
				$schema = new Schema();
				$schema->setId((int)$id);
				return $schema;
			}
		);

		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->method('find')->willReturnCallback(
			function (string|int $identifier, ?Register $register, ?Schema $schema, bool $includeDeleted, bool $_rbac, bool $_multitenancy): ObjectEntity {
				$this->calls[] = ['findObject', $identifier, $register?->getId(), $schema?->getId(), $includeDeleted, $_rbac, $_multitenancy];
				if ($this->object === null) {
					throw new \RuntimeException('gone');
				}

				return $this->object;
			}
		);

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->calls[] = ['dispatch', $event::class, $event->getObject() === $this->object];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				unset($context['line'], $context['file']);
				$this->calls[] = ['warning', $message, $context];
			}
		);

		$this->deps = [
			ITimeFactory::class            => $this->createMock(ITimeFactory::class),
			MagicMapperInterface::class    => $objects,
			RegisterMapperInterface::class => $registers,
			SchemaMapperInterface::class   => $schemas,
			IEventDispatcher::class        => $events,
			IUserSession::class            => $session,
			IUserManager::class            => $users,
			LoggerInterface::class         => $logger,
		];

		$this->object = new ObjectEntity();
		$this->object->setUuid('u-1');
	}//end setUp()

	/**
	 * Build a class from the collaborator map, constructing any type the map
	 * lacks from its own constructor.
	 *
	 * @param string $class The class to build.
	 *
	 * @return object
	 */
	private function build(string $class): object {
		if (isset($this->deps[$class]) === true) {
			return $this->deps[$class];
		}

		$reflection = new ReflectionClass($class);
		$args       = [];
		foreach (($reflection->getConstructor()?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type, $class.'::'.$parameter->getName());
			$args[] = $this->build($type->getName());
		}

		return $reflection->newInstanceArgs($args);
	}//end build()

	/**
	 * Run the job once.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 */
	private function runJob(mixed $argument): void {
		$job = $this->build(DeferredObjectEventJob::class);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	public function testMissingUuidDoesNothing(): void {
		$this->runJob(['registerId' => 1, 'user' => 'alice']);
		$this->runJob(null);

		$this->assertSame([], $this->calls);
	}//end testMissingUuidDoesNothing()

	public function testCreatedEventIsDispatchedAsTheActingUser(): void {
		$this->runJob(['uuid' => 'u-1', 'registerId' => '4', 'schemaId' => 7, 'user' => 'alice']);

		$this->assertSame(
			[
				['getUser', 'alice'],
				['setUser', 'alice'],
				['findRegister', 4, [true, true]],
				['findSchema', 7, [[], true, true]],
				['findObject', 'u-1', 4, 7, false, false, false],
				['dispatch', ObjectCreatedEvent::class, true],
				['setUser', null],
			],
			$this->calls
		);
	}//end testCreatedEventIsDispatchedAsTheActingUser()

	public function testDraftDispatchesTheDraftEvent(): void {
		$this->object->setStatus(ObjectEntity::STATUS_DRAFT);
		$this->runJob(['uuid' => 'u-1', 'user' => 'alice']);

		$this->assertContains(['dispatch', DraftCreatedEvent::class, true], $this->calls);
	}//end testDraftDispatchesTheDraftEvent()

	public function testWithoutAnActingUserItWarnsAndStillDispatches(): void {
		$this->runJob(['uuid' => 'u-1', 'user' => 'nobody']);
		$this->runJob(['uuid' => 'u-1']);

		$warning = '[DeferredObjectEventJob] No acting user for u-1; listeners that read organisation-scoped data will see nothing.';
		$this->assertSame(
			[
				['getUser', 'nobody'],
				['warning', $warning, ['uuid' => 'u-1', 'uid' => 'nobody']],
				['findObject', 'u-1', null, null, false, false, false],
				['dispatch', ObjectCreatedEvent::class, true],
				['warning', $warning, ['uuid' => 'u-1', 'uid' => '']],
				['findObject', 'u-1', null, null, false, false, false],
				['dispatch', ObjectCreatedEvent::class, true],
			],
			$this->calls
		);
	}//end testWithoutAnActingUserItWarnsAndStillDispatches()

	public function testAVanishedObjectWarnsAndRestoresTheSession(): void {
		$this->object = null;
		$this->runJob(['uuid' => 'u-9', 'user' => 'alice']);

		$this->assertSame(
			[
				['getUser', 'alice'],
				['setUser', 'alice'],
				['findObject', 'u-9', null, null, false, false, false],
				['warning', '[DeferredObjectEventJob] Could not dispatch deferred event for u-9: gone', ['uuid' => 'u-9']],
				['setUser', null],
			],
			$this->calls
		);
	}//end testAVanishedObjectWarnsAndRestoresTheSession()
}//end class
