<?php

/**
 * Golden test for the audience and payloads of NotifyPushListener.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\NotifyPushListener;
use OCA\OpenRegister\Listener\Support\PushSlugResolver;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Listener\Support\NotifyPushPlatform;

/**
 * Pins who receives which push: only the object's readers, on which channels,
 * with which body, and that the slug lookups bypass RBAC and multitenancy
 * because the listener is system-level.
 */
class NotifyPushListenerAudienceGoldenTest extends TestCase {

	/**
	 * Every push the fake queue received, in order.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $pushes = [];

	/**
	 * Every mapper lookup made, in order: [kind, id, rbac, multitenancy].
	 *
	 * @var array<int, array{0: string, 1: string, 2: bool, 3: bool}>
	 */
	private array $lookups = [];

	/**
	 * Values written to app config.
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $configWrites = [];

	/**
	 * Debug log messages.
	 *
	 * @var array<int, string>
	 */
	private array $debugs = [];

	/**
	 * Reset static state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		NotifyPushListener::resetStaticState();
	}//end setUp()

	/**
	 * Reset static state after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		NotifyPushListener::resetStaticState();
		parent::tearDown();
	}//end tearDown()

	/**
	 * A queue that records each push.
	 *
	 * @return object
	 */
	private function queue(): object {
		$test = $this;
		return new class($test) {
			/**
			 * Constructor.
			 *
			 * @param NotifyPushListenerAudienceGoldenTest $test The recording test.
			 */
			public function __construct(private NotifyPushListenerAudienceGoldenTest $test) {
			}//end __construct()

			/**
			 * Record one push.
			 *
			 * @param string               $type    Push type.
			 * @param array<string, mixed> $message Push message.
			 *
			 * @return void
			 */
			public function push(string $type, array $message): void {
				$this->test->recordPush(type: $type, message: $message);
			}//end push()
		};
	}//end queue()

	/**
	 * Record one push from the fake queue.
	 *
	 * @param string               $type    Push type.
	 * @param array<string, mixed> $message Push message.
	 *
	 * @return void
	 */
	public function recordPush(string $type, array $message): void {
		$this->pushes[] = [$type, $message];
	}//end recordPush()

	/**
	 * Build the listener under test.
	 *
	 * @param array<int, string> $readers      Users who may read the object.
	 * @param bool               $queueExists  Whether notify_push is resolvable.
	 * @param bool               $slugsResolve Whether the mappers find the register and schema.
	 * @param string             $pushFlag     Current push_available value.
	 *
	 * @return NotifyPushListener
	 */
	private function listener(
		array $readers,
		bool $queueExists = true,
		bool $slugsResolve = true,
		string $pushFlag = ''
	): NotifyPushListener {
		$queue = $this->queue();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($queue, $queueExists) {
				if ($id === 'OCA\NotifyPush\Queue\IQueue' && $queueExists === true) {
					return $queue;
				}

				throw new \RuntimeException('no ' . $id);
			}
		);

		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('getReadableByUsers')->willReturn($readers);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($pushFlag);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->configWrites[] = [$app, $key, $value];
				return true;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message): void {
				$this->debugs[] = $message;
			}
		);

		return $this->construct(
			logger: $logger,
			container: $container,
			permissions: $permissions,
			appConfig: $appConfig,
			mappers: $this->mappers(resolve: $slugsResolve)
		);
	}//end listener()

	/**
	 * Construct the listener from its collaborators.
	 *
	 * @param LoggerInterface                                       $logger      Logger.
	 * @param ContainerInterface                                    $container   Container.
	 * @param PermissionHandlerInterface                            $permissions Permission handler.
	 * @param IAppConfig                                            $appConfig   App config.
	 * @param array{0: RegisterMapperInterface, 1: SchemaMapperInterface} $mappers Register and schema mapper.
	 *
	 * @return NotifyPushListener
	 */
	private function construct(
		LoggerInterface $logger,
		ContainerInterface $container,
		PermissionHandlerInterface $permissions,
		IAppConfig $appConfig,
		array $mappers
	): NotifyPushListener {
		return new NotifyPushListener(
			new NotifyPushPlatform($logger, $container, $appConfig),
			$permissions,
			new PushSlugResolver($mappers[0], $mappers[1])
		);
	}//end construct()

	/**
	 * Register and schema mappers that record their lookups.
	 *
	 * @param bool $resolve Whether the lookups succeed.
	 *
	 * @return array{0: RegisterMapperInterface, 1: SchemaMapperInterface}
	 */
	private function mappers(bool $resolve): array {
		$register = $this->getMockBuilder(Register::class)->addMethods(['getSlug'])->getMock();
		$register->method('getSlug')->willReturn('reg-slug');
		$schema = $this->getMockBuilder(Schema::class)->addMethods(['getSlug'])->getMock();
		$schema->method('getSlug')->willReturn('schema-slug');

		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$registerMapper->method('find')->willReturnCallback(
			function (string|int $id, bool $_rbac = true, bool $_multitenancy = true) use ($register, $resolve) {
				$this->lookups[] = ['register', (string)$id, $_rbac, $_multitenancy];
				if ($resolve === false) {
					throw new \RuntimeException('Register not found');
				}

				return $register;
			}
		);

		$schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use ($schema, $resolve) {
				$this->lookups[] = ['schema', (string)$id, $_rbac, $_multitenancy];
				if ($resolve === false) {
					throw new \RuntimeException('Schema not found');
				}

				return $schema;
			}
		);

		return [$registerMapper, $schemaMapper];
	}//end mappers()

	/**
	 * An object in register 7, schema 9.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid = 'obj-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('7');
		$object->setSchema('9');
		$object->setVersion('1.0.2');
		return $object;
	}//end object()

	/**
	 * The body every lifecycle push carries.
	 *
	 * @param string      $action   Action.
	 * @param string|null $register Register slug.
	 * @param string|null $schema   Schema slug.
	 *
	 * @return array<string, mixed>
	 */
	private function body(string $action, ?string $register = 'reg-slug', ?string $schema = 'schema-slug'): array {
		return ['action' => $action, 'register' => $register, 'schema' => $schema, 'uuid' => 'obj-1', 'version' => '1.0.2'];
	}//end body()

	/**
	 * One targeted push.
	 *
	 * @param string               $user    Recipient.
	 * @param string               $channel Channel.
	 * @param array<string, mixed> $body    Body.
	 *
	 * @return array{0: string, 1: array<string, mixed>}
	 */
	private function push(string $user, string $channel, array $body): array {
		return ['notify_custom', ['user' => $user, 'message' => $channel, 'body' => $body]];
	}//end push()

	/**
	 * A create reaches exactly the readers, on the object then the collection channel.
	 *
	 * @return void
	 */
	public function testCreateReachesOnlyReadersOnObjectAndCollectionChannels(): void {
		$this->listener(readers: ['alice', 'bob'])->handle(new ObjectCreatedEvent($this->object()));

		$body = $this->body(action: 'create');
		$this->assertSame(
			[
				$this->push(user: 'alice', channel: 'or-object-obj-1', body: $body),
				$this->push(user: 'bob', channel: 'or-object-obj-1', body: $body),
				$this->push(user: 'alice', channel: 'or-collection-reg-slug-schema-slug', body: $body),
				$this->push(user: 'bob', channel: 'or-collection-reg-slug-schema-slug', body: $body),
			],
			$this->pushes
		);
		$this->assertSame([['register', '7', false, false], ['schema', '9', false, false]], $this->lookups);
		$this->assertSame([['openregister', 'push_available', '1']], $this->configWrites);
	}//end testCreateReachesOnlyReadersOnObjectAndCollectionChannels()

	/**
	 * A delete pushes the collection channel too; an update does not.
	 *
	 * @return void
	 */
	public function testDeleteAddsCollectionAndUpdateDoesNot(): void {
		$this->listener(readers: ['alice'])->handle(new ObjectDeletedEvent($this->object()));
		$this->listener(readers: ['alice'])->handle(new ObjectUpdatedEvent($this->object()));

		$this->assertSame(
			[
				$this->push(user: 'alice', channel: 'or-object-obj-1', body: $this->body(action: 'delete')),
				$this->push(user: 'alice', channel: 'or-collection-reg-slug-schema-slug', body: $this->body(action: 'delete')),
				$this->push(user: 'alice', channel: 'or-object-obj-1', body: $this->body(action: 'update')),
			],
			$this->pushes
		);
	}//end testDeleteAddsCollectionAndUpdateDoesNot()

	/**
	 * Nobody who may read the object gets nothing, and the flag is not raised.
	 *
	 * @return void
	 */
	public function testNoReadersReceivesNothing(): void {
		$this->listener(readers: [])->handle(new ObjectCreatedEvent($this->object()));

		$this->assertSame([], $this->pushes);
		$this->assertSame([], $this->configWrites);
	}//end testNoReadersReceivesNothing()

	/**
	 * An already-raised flag is not written again.
	 *
	 * @return void
	 */
	public function testRaisedFlagIsNotRewritten(): void {
		$this->listener(readers: ['alice'], pushFlag: '1')->handle(new ObjectUpdatedEvent($this->object()));

		$this->assertCount(1, $this->pushes);
		$this->assertSame([], $this->configWrites);
	}//end testRaisedFlagIsNotRewritten()

	/**
	 * Unresolvable slugs leave the body slugs null and skip the collection channel.
	 *
	 * @return void
	 */
	public function testUnresolvedSlugsSkipTheCollectionChannel(): void {
		$this->listener(readers: ['alice'], slugsResolve: false)->handle(new ObjectCreatedEvent($this->object()));

		$this->assertSame(
			[$this->push(user: 'alice', channel: 'or-object-obj-1', body: $this->body(action: 'create', register: null, schema: null))],
			$this->pushes
		);
	}//end testUnresolvedSlugsSkipTheCollectionChannel()

	/**
	 * The same (uuid, action) fires once per request; an object without uuid never.
	 *
	 * @return void
	 */
	public function testDeduplicatesAndIgnoresObjectsWithoutUuid(): void {
		$listener = $this->listener(readers: ['alice']);
		$listener->handle(new ObjectUpdatedEvent($this->object()));
		$listener->handle(new ObjectUpdatedEvent($this->object()));
		$listener->handle(new ObjectUpdatedEvent($this->object(uuid: '')));

		$this->assertCount(1, $this->pushes);
	}//end testDeduplicatesAndIgnoresObjectsWithoutUuid()

	/**
	 * Without notify_push nothing is pushed and one debug line is logged per request.
	 *
	 * @return void
	 */
	public function testMissingQueueLogsOneDebugLine(): void {
		$listener = $this->listener(readers: ['alice'], queueExists: false);
		$listener->handle(new ObjectCreatedEvent($this->object()));
		$listener->handle(new ObjectDeletedEvent($this->object()));

		$this->assertSame([], $this->pushes);
		$this->assertSame(['[NotifyPushListener] notify_push IQueue not available; push disabled for this request'], $this->debugs);
		$this->assertFalse($listener->pushPresence(object: $this->object(), present: [['user' => 'x']]));
	}//end testMissingQueueLogsOneDebugLine()

	/**
	 * Batch mode accumulates collections and the flush broadcasts them once.
	 *
	 * @return void
	 */
	public function testBatchModeAccumulatesAndFlushBroadcasts(): void {
		NotifyPushListener::setBatchMode(true);
		$this->listener(readers: ['alice'])->handle(new ObjectCreatedEvent($this->object()));
		$this->assertTrue(NotifyPushListener::hasBatchedCollections());
		$this->assertSame([], $this->pushes);

		NotifyPushListener::flushBatch($this->queue());

		$this->assertSame(
			[['notify_custom', ['message' => 'or-collection-reg-slug-schema-slug', 'body' => ['action' => 'batch', 'register' => 'reg-slug', 'schema' => 'schema-slug']]]],
			$this->pushes
		);
		$this->assertFalse(NotifyPushListener::hasBatchedCollections());
	}//end testBatchModeAccumulatesAndFlushBroadcasts()

	/**
	 * Presence reaches exactly the object's readers.
	 *
	 * @return void
	 */
	public function testPresenceReachesOnlyReaders(): void {
		$listener = $this->listener(readers: ['alice']);
		$this->assertTrue($listener->pushPresence(object: $this->object(), present: [['user' => 'bob']]));

		$this->assertSame(
			[$this->push(user: 'alice', channel: 'or-object-obj-1', body: ['action' => 'presence', 'uuid' => 'obj-1', 'present' => [['user' => 'bob']]])],
			$this->pushes
		);
		$this->assertFalse($this->listener(readers: [])->pushPresence(object: $this->object(), present: []));
		$this->assertFalse($listener->pushPresence(object: $this->object(uuid: ''), present: []));
	}//end testPresenceReachesOnlyReaders()
}//end class
