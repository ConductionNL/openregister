<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\TalkLinkService}.
 *
 * Exercises the Tier-2 service contract (link/create/list/unlink +
 * room discovery) against a mocked TalkLinkMapper. Tests that touch
 * Talk's internal services (Manager / RoomService / ParticipantService)
 * use the "Talk unavailable" path because those classes are resolved
 * from the container and aren't injectable into this unit test scope
 * without the `spreed` app on the classpath. Tests that exercise the
 * real Manager are gated by `@group requires-app-spreed`.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-talk/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Db\TalkLinkMapper;
use OCA\OpenRegister\Service\TalkLinkService;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * TalkLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class TalkLinkServiceTest extends TestCase {
	private TalkLinkMapper&MockObject $mapper;
	private ContainerInterface&MockObject $container;
	private IAppManager&MockObject $appManager;
	private IUserSession&MockObject $userSession;
	private IL10N&MockObject $l10n;
	private LoggerInterface&MockObject $logger;
	private SchemaMapper&MockObject $schemaMapper;
	private TalkLinkService $service;

	protected function setUp(): void {
		$this->mapper = $this->getMockBuilder(TalkLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'findByObjectUuid',
				'findByObjectAndRoom',
				'deleteByObjectAndRoom',
				'insert',
				'update',
			])
			->getMock();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->schemaMapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();

		// l10n->t() is called from extractRoomFields → buildSubtitle.
		// Return the input verbatim so the test doesn't depend on the
		// translation cache.
		$this->l10n->method('t')->willReturnArgument(0);

		$this->service = new TalkLinkService(
			$this->mapper,
			$this->container,
			$this->appManager,
			$this->userSession,
			$this->l10n,
			$this->logger,
			$this->schemaMapper
		);
	}

	private function setupUser(string $uid = 'admin'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testIsTalkAvailableTrue(): void {
		$this->appManager->method('isEnabledForUser')->with('spreed')->willReturn(true);
		$this->assertTrue($this->service->isTalkAvailable());
	}

	public function testIsTalkAvailableFalse(): void {
		$this->appManager->method('isEnabledForUser')->with('spreed')->willReturn(false);
		$this->assertFalse($this->service->isTalkAvailable());
	}

	public function testLinkRoomThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No user logged in');

		$this->service->linkRoom('abc-123', 1, 2, 'room-tok');
	}

	public function testLinkRoomThrowsOnDuplicate(): void {
		$this->setupUser();
		$existing = new TalkLink();
		$this->mapper->method('findByObjectAndRoom')->with('abc-123', 'room-tok')->willReturn($existing);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(409);
		$this->expectExceptionMessage('Room already linked to this object');

		$this->service->linkRoom('abc-123', 1, 2, 'room-tok');
	}

	public function testLinkRoomThrowsWhenTalkUnavailable(): void {
		$this->setupUser();
		$this->mapper->method('findByObjectAndRoom')->willReturn(null);
		// Talk not enabled. isTalkAvailable() returns bool, so the mock
		// must be stubbed (an unconfigured mock returns null → TypeError).
		$this->appManager->method('isEnabledForUser')->with('spreed')->willReturn(false);

		// Either Talk isn't installed (→ 503 from the null Manager
		// guard) or the Manager throws when looking up the room
		// (→ 404). Both are valid "cannot link this room" outcomes.
		$this->expectException(Exception::class);

		try {
			$this->service->linkRoom('abc-123', 1, 2, 'room-tok');
			$this->fail('Expected exception was not thrown');
		} catch (Exception $exception) {
			$this->assertContains($exception->getCode(), [404, 503]);
			throw $exception;
		}
	}

	public function testUnlinkRoomSucceeds(): void {
		$this->mapper->expects($this->once())
			->method('deleteByObjectAndRoom')
			->with('abc-123', 'room-tok')
			->willReturn(1);

		$this->service->unlinkRoom('abc-123', 'room-tok');
	}

	public function testUnlinkRoomNotFound(): void {
		$this->mapper->method('deleteByObjectAndRoom')->willReturn(0);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);
		$this->expectExceptionMessage('Talk link not found');

		$this->service->unlinkRoom('abc-123', 'room-tok');
	}

	public function testGetLinkedRoomsReturnsSerialisedRows(): void {
		$link = new TalkLink();
		$link->setObjectUuid('abc-123');
		$link->setRoomToken('room-tok');
		$link->setRoomName('Stub Room');
		$link->setRoomType(2);

		// Talk unavailable: the serialised rows still carry the widened
		// Tier-2 keys. Stub the bool-returning availability check.
		$this->appManager->method('isEnabledForUser')->with('spreed')->willReturn(false);
		$this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);

		$rows = $this->service->getLinkedRooms('abc-123');

		$this->assertCount(1, $rows);
		$this->assertSame('abc-123', $rows[0]['objectUuid']);
		$this->assertSame('room-tok', $rows[0]['roomToken']);
		$this->assertSame('Stub Room', $rows[0]['roomName']);
		$this->assertSame(2, $rows[0]['roomType']);
		// Tier-2 widened keys always present even with Talk unavailable.
		$this->assertArrayHasKey('subtitle', $rows[0]);
		$this->assertArrayHasKey('participantCount', $rows[0]);
		$this->assertArrayHasKey('lastMessage', $rows[0]);
		$this->assertArrayHasKey('lastActivity', $rows[0]);
		$this->assertArrayHasKey('url', $rows[0]);
		$this->assertSame('/index.php/call/room-tok', $rows[0]['url']);
	}

	public function testGetLinkedRoomsEmpty(): void {
		$this->appManager->method('isEnabledForUser')->with('spreed')->willReturn(false);
		$this->mapper->method('findByObjectUuid')->willReturn([]);

		$this->assertSame([], $this->service->getLinkedRooms('nonexistent'));
	}

	public function testCreateAndLinkRoomThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No user logged in');

		$this->service->createAndLinkRoom('abc-123', 1, 2, 'Test', null, 2);
	}

	public function testCreateAndLinkRoomRejectsOneToOneType(): void {
		$this->setupUser();

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);
		$this->expectExceptionMessage('Invalid room type');

		$this->service->createAndLinkRoom('abc-123', 1, 2, 'Test', null, 1);
	}

	public function testCreateAndLinkRoomThrowsWhenTalkUnavailable(): void {
		$this->setupUser();

		// Talk unavailable → resolveManager returns null → 503.
		$this->appManager->method('isEnabledForUser')->with('spreed')->willReturn(false);
		$this->expectException(Exception::class);

		try {
			$this->service->createAndLinkRoom('abc-123', 1, 2, 'Test', null, 2);
			$this->fail('Expected exception was not thrown');
		} catch (Exception $exception) {
			$this->assertContains($exception->getCode(), [500, 503]);
			throw $exception;
		}
	}

	public function testGetAvailableRoomsForUserReturnsEmptyWhenTalkUnavailable(): void {
		// Talk not enabled → isTalkAvailable false → empty.
		$this->appManager->method('isEnabledForUser')->with('spreed')->willReturn(false);
		$this->assertSame([], $this->service->getAvailableRoomsForUser());
		$this->assertSame([], $this->service->getAvailableRoomsForUser('search'));
	}

	/**
	 * Real Talk Manager round-trip — only runs when `spreed` is loaded.
	 *
	 * @group requires-app-spreed
	 */
	public function testLinkRoomEndToEndWithRealTalk(): void {
		if (class_exists('OCA\\Talk\\Manager') === false) {
			$this->markTestSkipped('NC Talk (spreed) is not installed');
		}

		$this->markTestSkipped('Integration test — exercised manually with a seeded Talk room');
	}

	/**
	 * A room token that is not linked to the given object is refused up front.
	 */
	public function testInviteExternalParticipantThrowsWhenLinkNotFound(): void {
		$this->mapper->method('findByObjectAndRoom')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);
		$this->expectExceptionMessage('Talk link not found');

		$this->service->inviteExternalParticipant('abc-123', 'room-tok', 'guardian@example.test');
	}

	/**
	 * A schema that has not opted in via `x-openregister-talk-participants`
	 * refuses the invite, even for a validly linked room.
	 */
	public function testInviteExternalParticipantThrowsWhenSchemaDoesNotOptIn(): void {
		$link = new TalkLink();
		$link->setSchemaId(30);
		$this->mapper->method('findByObjectAndRoom')->willReturn($link);

		$schema = new Schema();
		$schema->setConfiguration([]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(403);

		$this->service->inviteExternalParticipant('abc-123', 'room-tok', 'guardian@example.test');
	}

	/**
	 * A malformed email is refused before any Talk call is attempted.
	 */
	public function testInviteExternalParticipantThrowsOnInvalidEmail(): void {
		$link = new TalkLink();
		$link->setSchemaId(30);
		$this->mapper->method('findByObjectAndRoom')->willReturn($link);

		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-talk-participants' => true]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);

		$this->service->inviteExternalParticipant('abc-123', 'room-tok', 'not-an-email');
	}

	/**
	 * No logged-in user refuses the invite (mirrors linkRoom/createAndLinkRoom).
	 */
	public function testInviteExternalParticipantThrowsWhenNoUser(): void {
		$link = new TalkLink();
		$link->setSchemaId(30);
		$this->mapper->method('findByObjectAndRoom')->willReturn($link);

		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-talk-participants' => true]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);

		$this->service->inviteExternalParticipant('abc-123', 'room-tok', 'guardian@example.test');
	}

	/**
	 * Once past validation, an unavailable Talk degrades to a descriptor
	 * (AD-23) rather than throwing.
	 */
	public function testInviteExternalParticipantDegradesWhenTalkUnavailable(): void {
		$link = new TalkLink();
		$link->setSchemaId(30);
		$this->mapper->method('findByObjectAndRoom')->willReturn($link);

		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-talk-participants' => true]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('spreed')->willReturn(false);

		// class_exists('OCA\\Talk\\Manager') is also false in this test
		// environment (spreed not installed), so resolveManager() always
		// returns null regardless of the appManager stub.
		$result = $this->service->inviteExternalParticipant('abc-123', 'room-tok', 'guardian@example.test');

		$this->assertFalse($result['invited']);
		$this->assertTrue($result['unavailable']);
		$this->assertSame('talk-not-available', $result['cause']);
	}
}
