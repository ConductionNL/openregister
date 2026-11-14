<?php

/**
 * ContextChatSubmissionListener edge-path unit tests.
 *
 * Context Chat that cannot be resolved, a schema that cannot be read, an
 * authorization that cannot be resolved (fail closed), a failing or repeated
 * seen-user enumeration, an object without a uuid or name, and a deferred
 * event that carries no object.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\AuthorizationUnresolvableException;
use OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionListener;
use OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionServices;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\ContextChat\ContentItem;
use OCP\ContextChat\IContentManager;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionListener
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Mcp\Listener\ContextChatCandidate
 * @uses \OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionServices
 * @uses \OCA\OpenRegister\Service\Object\GroupRuleRequest
 * @uses \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 */
class ContextChatSubmissionListenerEdgesTest extends TestCase {

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemaMapper;

	/** @var PermissionHandlerInterface&MockObject */
	private PermissionHandlerInterface $permissionHandler;

	/** @var IContentManager&MockObject */
	private IContentManager $contentManager;

	/** @var IUserManager&MockObject */
	private IUserManager $userManager;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var ContainerInterface&MockObject */
	private ContainerInterface $container;

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$this->permissionHandler = $this->createMock(PermissionHandlerInterface::class);
		$this->contentManager = $this->createMock(IContentManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			fn (string $id) => $id === IContentManager::class ? $this->contentManager : null
		);
	}

	private function listener(?ListenerDeferralInterface $deferral = null): ContextChatSubmissionListener {
		return new ContextChatSubmissionListener(
			new ContextChatSubmissionServices(
				$this->container,
				$this->schemaMapper,
				$this->userManager,
				$this->logger,
				$deferral,
			),
			$this->permissionHandler,
		);
	}

	private function object(?string $uuid = 'obj-uuid-1', string $schemaId = '3', array $data = ['name' => 'Test Object']): ObjectEntity {
		$object = new ObjectEntity();
		if ($uuid !== null) {
			$object->setUuid($uuid);
		}

		$object->setRegister('1');
		$object->setSchema($schemaId);
		$object->setOwner('alice');
		$object->setObject($data);

		return $object;
	}

	private function optedInSchema(): Schema {
		$schema = new Schema();
		$schema->setId(3);
		$schema->setConfiguration(['x-openregister-contextchat' => true]);

		return $schema;
	}

	private function publiclyReadable(array $readers): void {
		$this->permissionHandler->method('resolveAuthorization')->willReturn(['read' => ['public']]);
		$this->permissionHandler->method('hasGroupPermission')->willReturn(true);
		$this->permissionHandler->method('getReadableByUsers')->willReturn($readers);
	}

	/**
	 * Capture the single item each submitContent() call carries.
	 *
	 * @param array<int, ContentItem> $captured Receives the items.
	 */
	private function captureSubmissions(array &$captured, int $times): void {
		$this->contentManager->expects($this->exactly($times))
			->method('submitContent')
			->with(
				$this->identicalTo('openregister'),
				$this->callback(function (array $items) use (&$captured): bool {
					$captured[] = $items[0];
					return true;
				})
			);
	}

	/**
	 * A container that cannot produce the content manager means Context Chat
	 * is unavailable: nothing is submitted or removed, and nothing throws.
	 */
	public function testAnUnresolvableContentManagerSubmitsAndRemovesNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no context chat'));
		$this->container = $container;
		$this->schemaMapper->method('find')->willReturn($this->optedInSchema());
		$this->publiclyReadable(['alice']);
		$this->contentManager->expects($this->never())->method('submitContent');
		$this->contentManager->expects($this->never())->method('deleteContent');
		$this->logger->expects($this->exactly(2))
			->method('debug')
			->with($this->stringContains('Context Chat unavailable: no context chat'));

		$listener = $this->listener();

		$this->assertTrue($listener->submitIfEligible($this->object()));
		$listener->remove($this->object());
	}

	/**
	 * An object without a schema id is not looked up and not submitted.
	 */
	public function testAnObjectWithoutASchemaIsNotSubmitted(): void {
		$this->schemaMapper->expects($this->never())->method('find');
		$this->contentManager->expects($this->never())->method('submitContent');

		$this->assertFalse($this->listener()->submitIfEligible($this->object(schemaId: '')));
	}

	/**
	 * A schema the mapper cannot find is logged and the object is not submitted.
	 */
	public function testAnUnreadableSchemaIsLoggedAndNotSubmitted(): void {
		$this->schemaMapper->expects($this->once())
			->method('find')
			->with('3', [], false, false)
			->willThrowException(new RuntimeException('schema gone'));
		$this->contentManager->expects($this->never())->method('submitContent');
		$this->logger->expects($this->once())
			->method('debug')
			->with(
				'[ContextChatSubmissionListener] Failed to resolve schema for object',
				$this->callback(static fn (array $context): bool => $context['schemaId'] === '3' && $context['error'] === 'schema gone')
			);

		$this->assertFalse($this->listener()->submitIfEligible($this->object()));
	}

	/**
	 * An authorization that cannot be resolved fails closed: the object is
	 * not submitted and any earlier submission is removed.
	 */
	public function testAnUnresolvableAuthorizationRemovesInsteadOfSubmitting(): void {
		$this->schemaMapper->method('find')->willReturn($this->optedInSchema());
		$this->permissionHandler->method('resolveAuthorization')
			->willThrowException(new AuthorizationUnresolvableException('unresolvable'));
		$this->permissionHandler->expects($this->never())->method('hasGroupPermission');
		$this->contentManager->expects($this->never())->method('submitContent');
		$this->contentManager->expects($this->once())
			->method('deleteContent')
			->with('openregister', 'openregister_objects', ['obj-uuid-1']);

		$this->assertFalse($this->listener()->submitIfEligible($this->object()));
	}

	/**
	 * The seen users are enumerated once per listener and reused for the next
	 * broadcast submission.
	 */
	public function testSeenUsersAreEnumeratedOnce(): void {
		$this->schemaMapper->method('find')->willReturn($this->optedInSchema());
		$this->publiclyReadable([]);
		$this->userManager->expects($this->once())
			->method('callForSeenUsers')
			->willReturnCallback(function (\Closure $callback): void {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn('dave');
				$callback($user);
			});
		$captured = [];
		$this->captureSubmissions($captured, 2);

		$listener = $this->listener();
		$listener->submitIfEligible($this->object());
		$listener->submitIfEligible($this->object(uuid: 'obj-uuid-2'));

		$this->assertSame(['dave'], $captured[0]->users);
		$this->assertSame(['dave'], $captured[1]->users);
	}

	/**
	 * A failing seen-user enumeration is logged and leaves an empty audience.
	 */
	public function testAFailingSeenUserEnumerationIsLogged(): void {
		$this->schemaMapper->method('find')->willReturn($this->optedInSchema());
		$this->publiclyReadable([]);
		$this->userManager->method('callForSeenUsers')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'[ContextChatSubmissionListener] Failed to enumerate seen users for broadcast audience',
				$this->callback(static fn (array $context): bool => $context['error'] === 'db down')
			);
		$captured = [];
		$this->captureSubmissions($captured, 1);

		$this->assertTrue($this->listener()->submitIfEligible($this->object()));
		$this->assertSame([], $captured[0]->users);
	}

	/**
	 * Without a name the title is the summary.
	 */
	public function testTheTitleFallsBackToTheSummary(): void {
		$this->schemaMapper->method('find')->willReturn($this->optedInSchema());
		$this->publiclyReadable(['alice']);
		$captured = [];
		$this->captureSubmissions($captured, 1);

		$this->listener()->submitIfEligible($this->object(data: ['summary' => 'Only a summary', 'count' => 2]));

		$this->assertSame('Only a summary', $captured[0]->title);
		$this->assertSame("id: obj-uuid-1\nsummary: Only a summary\ncount: 2", $captured[0]->content);
	}

	/**
	 * Removing an object without a uuid asks Context Chat for nothing.
	 */
	public function testRemovingAnObjectWithoutAUuidDeletesNothing(): void {
		$this->schemaMapper->method('find')->willReturn($this->optedInSchema());
		$this->contentManager->expects($this->never())->method('deleteContent');

		$this->listener()->remove($this->object(uuid: null));
	}

	/**
	 * With deferral on, an event that carries no object enqueues nothing.
	 */
	public function testADeferredEventWithoutAnObjectEnqueuesNothing(): void {
		$deferral = $this->createMock(ListenerDeferralInterface::class);
		$deferral->method('isDeferralEnabled')->willReturn(true);
		$deferral->expects($this->never())->method('defer');
		$this->schemaMapper->expects($this->never())->method('find');

		$this->listener($deferral)->handle(new Event());
	}
}
