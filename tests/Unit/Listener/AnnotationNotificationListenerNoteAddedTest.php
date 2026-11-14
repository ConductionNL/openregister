<?php

/**
 * The listener's `noteAdded` branch (notifications-new-notes-and-referrers task 1.2).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\BackgroundJob\JobHandles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectNoteAddedEvent;
use OCA\OpenRegister\Listener\AnnotationNotificationListener;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolverInterface;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Listener\AnnotationNotificationListener
 */
class AnnotationNotificationListenerNoteAddedTest extends TestCase {
	private AnnotationNotificationDispatcher&MockObject $dispatcher;
	private SchemaMapper&MockObject $schemaMapper;
	private ListenerDeferralService&MockObject $deferral;
	private DeferredEntryObjectResolverInterface&MockObject $objects;

	/** @var array<int, array<string, mixed>> */
	private array $deferred = [];

	/** @var array<int, array{0: string, 1: string, 2: array}> */
	private array $dispatched = [];

	/** @var array<int, array<string, mixed>> */
	private array $lookups = [];

	protected function setUp(): void {
		parent::setUp();
		$this->dispatcher   = $this->createMock(AnnotationNotificationDispatcher::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->deferral     = $this->createMock(ListenerDeferralService::class);
		$this->objects      = $this->createMock(DeferredEntryObjectResolverInterface::class);

		$this->deferral->method('defer')->willReturnCallback(
			function (string $jobClass, array $entry, int $chunkSize): void {
				$this->deferred[] = ['job' => $jobClass, 'entry' => $entry];
			}
		);
		$this->dispatcher->method('dispatch')->willReturnCallback(
			function (ObjectEntity $object, string $trigger, array $context = []): void {
				$this->dispatched[] = [(string)$object->getUuid(), $trigger, $context];
			}
		);
	}

	private function listener(bool $withObjects = true): AnnotationNotificationListener {
		$objects = null;
		if ($withObjects === true) {
			$objects = $this->objects;
		}

		return new AnnotationNotificationListener(
			dispatcher: $this->dispatcher,
			schemaMapper: $this->schemaMapper,
			deferral: $this->deferral,
			objects: $objects,
		);
	}

	private function schema(bool $declares): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$config = [];
		if ($declares === true) {
			$config['x-openregister-notifications'] = ['onNote' => ['trigger' => ['type' => 'noteAdded']]];
		}

		$schema->setConfiguration($config);
		return $schema;
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('task-1');
		$object->setRegister('5');
		$object->setSchema('9');
		$object->setVersion('1.0.2');
		return $object;
	}

	private function event(): ObjectNoteAddedEvent {
		return new ObjectNoteAddedEvent(objectUuid: 'task-1', noteId: '42', actorType: 'users', actorId: 'bob', visibility: 'internal');
	}

	private function objectFound(): void {
		$this->objects->method('resolve')->willReturnCallback(
			function (array $entry): ObjectEntity {
				$this->lookups[] = $entry;
				return $this->object();
			}
		);
	}

	public function testANoteDefersANoteAddedEntryWithTheNoteContext(): void {
		$this->objectFound();
		$this->schemaMapper->method('find')->with('9')->willReturn($this->schema(declares: true));
		$this->deferral->method('isDeferralEnabled')->willReturn(true);

		$this->listener()->handle($this->event());

		$this->assertSame([['uuid' => 'task-1']], $this->lookups);
		$this->assertSame([], $this->dispatched);
		$this->assertSame(
			[
				[
					'job' => JobHandles::ANNOTATION_NOTIFICATION_DISPATCH,
					'entry' => [
						'uuid' => 'task-1',
						'register' => '5',
						'schema' => '9',
						'version' => '1.0.2',
						'trigger' => 'noteAdded',
						'noteId' => '42',
						'actorType' => 'users',
						'actorId' => 'bob',
						'visibility' => 'internal',
					],
				],
			],
			$this->deferred
		);
	}

	public function testWithDeferralOffTheNoteIsDispatchedInline(): void {
		$this->objectFound();
		$this->schemaMapper->method('find')->willReturn($this->schema(declares: true));
		$this->deferral->method('isDeferralEnabled')->willReturn(false);

		$this->listener()->handle($this->event());

		$this->assertSame(
			[['task-1', 'noteAdded', ['noteId' => '42', 'actorType' => 'users', 'actorId' => 'bob', 'visibility' => 'internal']]],
			$this->dispatched
		);
		$this->assertSame([], $this->deferred);
	}

	public function testASchemaWithoutRulesEnqueuesNothing(): void {
		$this->objectFound();
		$this->schemaMapper->method('find')->willReturn($this->schema(declares: false));
		$this->deferral->method('isDeferralEnabled')->willReturn(true);

		$this->listener()->handle($this->event());

		$this->assertSame([], $this->deferred);
		$this->assertSame([], $this->dispatched);
	}

	public function testAnObjectThatNoLongerResolvesFiresNothing(): void {
		$this->objects->method('resolve')->willReturn(null);
		$this->schemaMapper->expects($this->never())->method('find');
		$this->deferral->method('isDeferralEnabled')->willReturn(true);

		$this->listener()->handle($this->event());

		$this->assertSame([], $this->deferred);
		$this->assertSame([], $this->dispatched);
	}

	public function testWithoutAnObjectLookupANoteFiresNothing(): void {
		$this->schemaMapper->expects($this->never())->method('find');
		$this->deferral->method('isDeferralEnabled')->willReturn(false);

		$this->listener(withObjects: false)->handle($this->event());

		$this->assertSame([], $this->dispatched);
	}
}
