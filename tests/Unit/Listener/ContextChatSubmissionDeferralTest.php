<?php

/**
 * ContextChatSubmissionListener deferral unit tests.
 *
 * With deferral on, the object lifecycle events enqueue a
 * ContextChatSubmissionJob entry instead of calling Context Chat in the
 * request; an opted-out schema enqueues nothing; with the kill switch off the
 * full inline path runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\BackgroundJob\JobHandles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionListener;
use OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionServices;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralInterface;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\ContextChat\IContentManager;
use OCP\EventDispatcher\Event;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionListener
 * @uses \OCA\OpenRegister\BackgroundJob\JobHandles
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses \OCA\OpenRegister\Event\ObjectCreatedEvent
 * @uses \OCA\OpenRegister\Event\ObjectDeletedEvent
 * @uses \OCA\OpenRegister\Event\ObjectUpdatedEvent
 * @uses \OCA\OpenRegister\Mcp\Listener\ContextChatCandidate
 * @uses \OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionServices
 * @uses \OCA\OpenRegister\Service\Object\GroupRuleRequest
 * @uses \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 */
class ContextChatSubmissionDeferralTest extends TestCase {
	private SchemaMapper&MockObject $schemaMapper;
	private PermissionHandler&MockObject $permissionHandler;
	private IContentManager&MockObject $contentManager;
	private ListenerDeferralInterface&MockObject $deferral;
	private ContextChatSubmissionListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->contentManager = $this->createMock(IContentManager::class);
		$this->deferral = $this->createMock(ListenerDeferralInterface::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => $id === IContentManager::class ? $this->contentManager : null
		);

		$this->listener = new ContextChatSubmissionListener(
			new ContextChatSubmissionServices(
				$container,
				$this->schemaMapper,
				$this->createMock(IUserManager::class),
				$this->createMock(LoggerInterface::class),
				$this->deferral,
			),
			$this->permissionHandler,
		);
	}

	private function object(string $uuid = 'obj-uuid-1', int $schemaId = 5): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('1');
		$object->setSchema((string)$schemaId);
		$object->setOwner('alice');
		$object->setObject(['name' => 'Test Object']);

		return $object;
	}

	private function schema(bool $optedIn): Schema {
		$schema = new Schema();
		$schema->setId(5);
		if ($optedIn === true) {
			$schema->setConfiguration(['x-openregister-contextchat' => true]);
		}

		return $schema;
	}

	/**
	 * @return array<string, array{0: Event}>
	 */
	public static function events(): array {
		$object = new ObjectEntity();
		$object->setUuid('obj-uuid-1');
		$object->setSchema('5');

		return [
			'created' => [new ObjectCreatedEvent(object: $object)],
			'updated' => [new ObjectUpdatedEvent($object, null)],
			'deleted' => [new ObjectDeletedEvent(object: $object)],
		];
	}

	/**
	 * @dataProvider events
	 */
	public function testEachEventEnqueuesOneEntryInsteadOfCallingContextChat(Event $event): void {
		$this->deferral->method('isDeferralEnabled')->willReturn(true);
		$this->schemaMapper->method('find')->willReturn($this->schema(optedIn: true));

		$this->deferral->expects($this->once())->method('defer')->with(
			JobHandles::CONTEXT_CHAT_SUBMISSION,
			['uuid' => 'obj-uuid-1', 'schemaId' => '5'],
			ListenerDeferralInterface::DEFAULT_CHUNK_SIZE,
			'obj-uuid-1'
		);
		$this->contentManager->expects($this->never())->method('submitContent');
		$this->contentManager->expects($this->never())->method('deleteContent');
		$this->permissionHandler->expects($this->never())->method('hasGroupPermission');

		$this->listener->handle($event);
	}

	public function testAnOptedOutSchemaEnqueuesNothing(): void {
		$this->deferral->method('isDeferralEnabled')->willReturn(true);
		$this->schemaMapper->method('find')->willReturn($this->schema(optedIn: false));

		$this->deferral->expects($this->never())->method('defer');

		$this->listener->handle(new ObjectCreatedEvent(object: $this->object()));
	}

	public function testAnObjectWithoutUuidEnqueuesNothing(): void {
		$this->deferral->method('isDeferralEnabled')->willReturn(true);
		$this->schemaMapper->method('find')->willReturn($this->schema(optedIn: true));

		$this->deferral->expects($this->never())->method('defer');

		$this->listener->handle(new ObjectCreatedEvent(object: $this->object(uuid: '')));
	}

	public function testTheKillSwitchRunsTheInlinePath(): void {
		$this->deferral->method('isDeferralEnabled')->willReturn(false);
		$this->schemaMapper->method('find')->willReturn($this->schema(optedIn: true));

		$this->deferral->expects($this->never())->method('defer');
		$this->contentManager->expects($this->once())
			->method('deleteContent')
			->with('openregister', 'openregister_objects', ['obj-uuid-1']);

		$this->listener->handle(new ObjectDeletedEvent(object: $this->object()));
	}

	public function testAFailingEnqueueNeverBreaksTheWrite(): void {
		$this->deferral->method('isDeferralEnabled')->willReturn(true);
		$this->schemaMapper->method('find')->willReturn($this->schema(optedIn: true));
		$this->deferral->method('defer')->willThrowException(new \RuntimeException('job list down'));

		$this->listener->handle(new ObjectCreatedEvent(object: $this->object()));

		$this->addToAssertionCount(1);
	}
}
