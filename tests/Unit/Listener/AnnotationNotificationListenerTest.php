<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\BackgroundJob\AnnotationNotificationDispatchJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\AnnotationNotificationListener;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The listener defers notification dispatch to
 * AnnotationNotificationDispatchJob for schemas declaring
 * x-openregister-notifications, capturing the pre-update snapshot for
 * `updated` entries; the kill switch restores the inline dispatch pair.
 */
class AnnotationNotificationListenerTest extends TestCase {
	private AnnotationNotificationDispatcher&MockObject $dispatcher;
	private SchemaMapper&MockObject $schemaMapper;
	private ListenerDeferralService&MockObject $deferral;

	protected function setUp(): void {
		parent::setUp();
		$this->dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->deferral = $this->createMock(ListenerDeferralService::class);
	}

	private function makeListener(): AnnotationNotificationListener {
		return new AnnotationNotificationListener(
			dispatcher: $this->dispatcher,
			schemaMapper: $this->schemaMapper,
			deferral: $this->deferral,
		);
	}

	private function schemaWithNotifications(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('test-schema');
		$schema->setConfiguration([
			'x-openregister-notifications' => [
				'onSomething' => [
					'trigger' => ['type' => 'created'],
					'channels' => ['nc-notification'],
					'subject' => 'hello',
					'recipients' => [['kind' => 'users', 'users' => ['admin']]],
				],
			],
		]);
		return $schema;
	}

	private function fixtureObject(array $data = []): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('test-register');
		$object->setSchema('test-schema');
		$object->setObject($data);
		return $object;
	}

	public function testCreatedEventDefersACreatedEntry(): void {
		$this->schemaMapper->method('find')->willReturn($this->schemaWithNotifications());
		$this->deferral->method('isDeferralEnabled')->willReturn(true);

		// Dispatch (which can do outbound HTTP/mail) must NOT run inline.
		$this->dispatcher->expects($this->never())->method('dispatch');
		$this->deferral->expects($this->once())->method('defer')
			->willReturnCallback(
				function (string $jobClass, array $entry): void {
					$this->assertSame(AnnotationNotificationDispatchJob::class, $jobClass);
					$this->assertSame('obj-1', $entry['uuid']);
					$this->assertSame('created', $entry['trigger']);
				}
			);

		$this->makeListener()->handle(new ObjectCreatedEvent($this->fixtureObject()));
	}

	/**
	 * Decision 216: a draft's first save and its autosaves notify nobody.
	 */
	public function testADraftNotifiesNobody(): void {
		$this->schemaMapper->method('find')->willReturn($this->schemaWithNotifications());
		$this->deferral->method('isDeferralEnabled')->willReturn(true);
		$this->dispatcher->expects($this->never())->method('dispatch');
		$this->deferral->expects($this->never())->method('defer');

		$draft = $this->fixtureObject(['title' => 'Bin']);
		$draft->setStatus('draft');
		$older = $this->fixtureObject(['title' => 'B']);
		$older->setStatus('draft');

		$this->makeListener()->handle(new ObjectCreatedEvent($draft));
		$this->makeListener()->handle(new ObjectUpdatedEvent($draft, $older));
	}

	/**
	 * Leaving draft is the object's `created` moment, announced once: the save that left draft is not an `updated` too.
	 */
	public function testLeavingDraftIsAnnouncedAsCreatedOnce(): void {
		$this->schemaMapper->method('find')->willReturn($this->schemaWithNotifications());
		$this->deferral->method('isDeferralEnabled')->willReturn(true);
		$entries = [];
		$this->deferral->method('defer')->willReturnCallback(
			function (string $jobClass, array $entry) use (&$entries): void {
				$entries[] = $entry['trigger'] . ':' . $entry['uuid'];
			}
		);

		$active = $this->fixtureObject(['title' => 'Bin not collected']);
		$active->setStatus('active');
		$draft = $this->fixtureObject(['title' => 'Bin']);
		$draft->setStatus('draft');

		// The activation saves the object (an update event) and then announces it.
		$this->makeListener()->handle(new ObjectUpdatedEvent($active, $draft));
		$this->makeListener()->handle(new \OCA\OpenRegister\Event\ObjectActivatedEvent(object: $active, receivedAt: new \DateTimeImmutable('2026-10-12T16:00:00+02:00')));

		$this->assertSame(['created:obj-1'], $entries);
	}

	/**
	 * Control: an object without a stored status, created outright, is announced as before.
	 */
	public function testAnObjectCreatedOutrightIsStillAnnounced(): void {
		$this->schemaMapper->method('find')->willReturn($this->schemaWithNotifications());
		$this->deferral->method('isDeferralEnabled')->willReturn(false);
		$this->dispatcher->expects($this->once())->method('dispatch')->with($this->anything(), 'created');

		$active = $this->fixtureObject(['title' => 'x']);
		$active->setStatus('active');
		$this->makeListener()->handle(new ObjectCreatedEvent($active));
	}

	public function testUpdatedEventCapturesThePreUpdateSnapshot(): void {
		$this->schemaMapper->method('find')->willReturn($this->schemaWithNotifications());
		$this->deferral->method('isDeferralEnabled')->willReturn(true);

		$newObject = $this->fixtureObject(['title' => 'after']);
		$oldObject = $this->fixtureObject(['title' => 'before']);

		$this->dispatcher->expects($this->never())->method('dispatch');
		$this->deferral->expects($this->once())->method('defer')
			->willReturnCallback(
				function (string $jobClass, array $entry): void {
					$this->assertSame('updated', $entry['trigger']);
					// The old state is unrecoverable later — snapshot it now.
					// (ObjectEntity::getObject() prepends the uuid as `id`.)
					$this->assertSame(['id' => 'obj-1', 'title' => 'before'], $entry['oldData']);
				}
			);

		$this->makeListener()->handle(new ObjectUpdatedEvent($newObject, $oldObject));
	}

	public function testTransitionEventForwardsTheTransitionContext(): void {
		$this->schemaMapper->method('find')->willReturn($this->schemaWithNotifications());
		$this->deferral->method('isDeferralEnabled')->willReturn(true);

		$object = $this->fixtureObject();

		$this->deferral->expects($this->once())->method('defer')
			->willReturnCallback(
				function (string $jobClass, array $entry): void {
					$this->assertSame('transition', $entry['trigger']);
					$this->assertSame('approve', $entry['action']);
					$this->assertSame('draft', $entry['from']);
					$this->assertSame('published', $entry['to']);
				}
			);

		$this->makeListener()->handle(
			new ObjectTransitionedEvent(
				object: $object,
				action: 'approve',
				from: 'draft',
				to: 'published',
				userId: 'alice',
				register: 'test-register',
				schema: 'test-schema'
			)
		);
	}

	public function testSchemaWithoutNotificationsEnqueuesNothing(): void {
		$plain = new Schema();
		$plain->setId(2);
		$plain->setSlug('plain');
		$this->schemaMapper->method('find')->willReturn($plain);

		$this->dispatcher->expects($this->never())->method('dispatch');
		$this->deferral->expects($this->never())->method('defer');

		$this->makeListener()->handle(new ObjectCreatedEvent($this->fixtureObject()));
	}

	public function testKillSwitchDispatchesTheInlineUpdatedPair(): void {
		$this->schemaMapper->method('find')->willReturn($this->schemaWithNotifications());
		$this->deferral->method('isDeferralEnabled')->willReturn(false);

		$newObject = $this->fixtureObject(['title' => 'after']);
		$oldObject = $this->fixtureObject(['title' => 'before']);

		$dispatched = [];
		$this->dispatcher->expects($this->exactly(2))->method('dispatch')
			->willReturnCallback(
				function (ObjectEntity $object, string $trigger, array $context = []) use (&$dispatched): void {
					$dispatched[] = [$trigger, $context];
				}
			);
		$this->deferral->expects($this->never())->method('defer');

		$this->makeListener()->handle(new ObjectUpdatedEvent($newObject, $oldObject));

		$expectedContext = [
			// ObjectEntity::getObject() prepends the uuid as `id`.
			'_newData' => ['id' => 'obj-1', 'title' => 'after'],
			'_oldData' => ['id' => 'obj-1', 'title' => 'before'],
		];
		$this->assertSame(
			[
				['updated', $expectedContext],
				['calculatedChange', $expectedContext],
			],
			$dispatched
		);
	}
}
