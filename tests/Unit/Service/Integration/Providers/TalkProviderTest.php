<?php

/**
 * Unit tests for TalkProvider's leaf-row payload widening (Phase B-1).
 *
 * Talk's internal classes (Room, Manager, ParticipantService) are not
 * autoloaded in the unit-test bootstrap; the provider resolves them
 * through the container behind `method_exists` guards, so each test
 * uses anonymous classes that mimic the surface the provider touches.
 *
 * @group requires-app-internal-api The shipped payload depends on
 *     `OCA\\Talk\\Manager`, `OCA\\Talk\\Room`, and
 *     `OCA\\Talk\\Service\\ParticipantService`. These tests stub them
 *     via anonymous classes (no upstream fork).
 */

declare(strict_types=1);

namespace Unit\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\Providers\TalkProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class TalkProviderTest extends TestCase {
	private ContainerInterface&MockObject $container;
	private IAppManager&MockObject $appManager;
	private IUserSession&MockObject $userSession;
	private IL10N&MockObject $l10n;
	private TalkProvider $provider;

	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);

		$this->provider = new TalkProvider(
			$this->container,
			$this->appManager,
			$this->userSession,
			$this->l10n,
		);
	}

	/**
	 * Build an anonymous "Room" stub with the methods TalkProvider touches.
	 */
	private function makeRoom(
		string $token,
		string $name,
		int $type,
		?\DateTime $lastActivity = null,
		?object $lastMessage = null,
		string $description = '',
	): object {
		return new class($token, $name, $type, $lastActivity, $lastMessage, $description) {
			public function __construct(
				private string $token,
				private string $name,
				private int $type,
				private ?\DateTime $lastActivity,
				private ?object $lastMessage,
				private string $description,
			) {
			}

			public function getToken(): string {
				return $this->token;
			}

			public function getName(): ?string {
				return $this->name;
			}

			public function getDisplayName(string $userId): string {
				return $this->name;
			}

			public function getType(): int {
				return $this->type;
			}

			public function getDescription(): string {
				return $this->description;
			}

			public function getLastActivity(): ?\DateTime {
				return $this->lastActivity;
			}

			public function getLastMessage(): ?object {
				return $this->lastMessage;
			}
		};
	}

	/**
	 * Build an anonymous "IComment" stub.
	 */
	private function makeComment(string $message, string $actorType, string $actorId, \DateTime $created): object {
		return new class($message, $actorType, $actorId, $created) {
			public function __construct(
				private string $message,
				private string $actorType,
				private string $actorId,
				private \DateTime $created,
			) {
			}

			public function getMessage(): string {
				return $this->message;
			}

			public function getActorType(): string {
				return $this->actorType;
			}

			public function getActorId(): string {
				return $this->actorId;
			}

			public function getCreationDateTime(): \DateTime {
				return $this->created;
			}
		};
	}

	private function setupUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testListReturnsEmptyWhenTalkDisabled(): void {
		$this->appManager->method('isInstalled')->with('spreed')->willReturn(false);

		$this->assertSame([], $this->provider->list('r', 's', 'uuid', []));
	}

	public function testListFiltersByMarkerAndShipsWidenedPayload(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->setupUser();

		$marker = '[or:abc-123]';
		$rooms = [
			$this->makeRoom(
				token: 'tokenA',
				name: 'Case discussion ' . $marker,
				type: 2, // group
				lastActivity: new \DateTime('2026-05-01T10:00:00Z'),
				lastMessage: $this->makeComment('Hello team', 'users', 'admin', new \DateTime('2026-05-01T09:55:00Z')),
				description: '',
			),
			// Room without the marker — should be filtered out.
			$this->makeRoom('other', 'Unrelated', 2),
		];

		$manager = new class($rooms) {
			public function __construct(
				private array $rooms,
			) {
			}

			public function getRoomsForUser(string $userId, array $sessionIds = [], bool $includeLastMessage = false): array {
				return $this->rooms;
			}
		};

		$participantService = new class {
			public function getNumberOfActors(object $room): int {
				return 5;
			}
		};

		$this->container->method('get')->willReturnMap(
			[
				['OCA\\Talk\\Manager', $manager],
				['OCA\\Talk\\Service\\ParticipantService', $participantService],
			]
		);

		$rows = $this->provider->list('r', 's', 'abc-123', []);

		$this->assertCount(1, $rows);
		$row = $rows[0];
		$this->assertSame('tokenA', $row['id']);
		// Marker stripped from title.
		$this->assertSame('Case discussion', $row['title']);
		$this->assertSame(2, $row['type']);
		$this->assertSame('Group', $row['subtitle']);
		$this->assertSame(5, $row['participantCount']);
		$this->assertNull($row['unreadMessages']);
		$this->assertIsArray($row['lastMessage']);
		$this->assertSame('Hello team', $row['lastMessage']['text']);
		$this->assertSame('users', $row['lastMessage']['actor']['type']);
		$this->assertSame('admin', $row['lastMessage']['actor']['id']);
		$this->assertNotNull($row['lastMessage']['timestamp']);
		$this->assertSame('/index.php/call/tokenA', $row['url']);
	}

	public function testSubtitleFallsBackToTypeLabel(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->setupUser();

		$marker = '[or:x]';
		$cases = [
			['type' => 1, 'expected' => 'Direct message'],
			['type' => 2, 'expected' => 'Group'],
			['type' => 3, 'expected' => 'Public'],
			['type' => 4, 'expected' => 'System'],
			['type' => 6, 'expected' => 'Note to self'],
		];

		foreach ($cases as $case) {
			$rooms = [$this->makeRoom('t-' . $case['type'], 'name ' . $marker, $case['type'])];
			$manager = new class($rooms) {
				public function __construct(
					private array $rooms,
				) {
				}
				public function getRoomsForUser(string $userId, array $sessionIds = [], bool $includeLastMessage = false): array {
					return $this->rooms;
				}
			};

			// Fresh provider so ContainerInterface mock isn't poisoned by prior willReturnMap.
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				static function (string $id) use ($manager) {
					if ($id === 'OCA\\Talk\\Manager') {
						return $manager;
					}
					throw new \RuntimeException('not available');
				}
			);

			$provider = new TalkProvider($container, $this->appManager, $this->userSession, $this->l10n);

			$rows = $provider->list('r', 's', 'x', []);

			$this->assertCount(1, $rows, 'type=' . $case['type']);
			$this->assertSame($case['expected'], $rows[0]['subtitle']);
		}
	}

	public function testSubtitlePrefersDescription(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->setupUser();

		$marker = '[or:y]';
		$rooms = [
			$this->makeRoom('t', 'name ' . $marker, 2, description: 'Custom description'),
		];

		$manager = new class($rooms) {
			public function __construct(
				private array $rooms,
			) {
			}
			public function getRoomsForUser(string $userId, array $sessionIds = [], bool $includeLastMessage = false): array {
				return $this->rooms;
			}
		};
		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($manager) {
				if ($id === 'OCA\\Talk\\Manager') {
					return $manager;
				}
				throw new \RuntimeException('not available');
			}
		);

		$rows = $this->provider->list('r', 's', 'y', []);
		$this->assertSame('Custom description', $rows[0]['subtitle']);
	}

	public function testParticipantServiceMissingFallsBackToNullCount(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->setupUser();

		$marker = '[or:y]';
		$rooms = [$this->makeRoom('t', 'name ' . $marker, 2)];

		$manager = new class($rooms) {
			public function __construct(
				private array $rooms,
			) {
			}
			public function getRoomsForUser(string $userId, array $sessionIds = [], bool $includeLastMessage = false): array {
				return $this->rooms;
			}
		};

		// Container throws for ParticipantService.
		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($manager) {
				if ($id === 'OCA\\Talk\\Manager') {
					return $manager;
				}
				throw new \RuntimeException('ParticipantService unavailable');
			}
		);

		$rows = $this->provider->list('r', 's', 'y', []);
		$this->assertNull($rows[0]['participantCount']);
		// Participant lookup failure must not propagate.
		$this->assertSame('t', $rows[0]['id']);
	}

	public function testRoomWithNoLastMessageProducesNull(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->setupUser();

		$marker = '[or:z]';
		$rooms = [$this->makeRoom('t', 'name ' . $marker, 2, lastActivity: null, lastMessage: null)];

		$manager = new class($rooms) {
			public function __construct(
				private array $rooms,
			) {
			}
			public function getRoomsForUser(string $userId, array $sessionIds = [], bool $includeLastMessage = false): array {
				return $this->rooms;
			}
		};
		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($manager) {
				if ($id === 'OCA\\Talk\\Manager') {
					return $manager;
				}
				throw new \RuntimeException('not available');
			}
		);

		$rows = $this->provider->list('r', 's', 'z', []);
		$this->assertNull($rows[0]['lastMessage']);
		$this->assertNull($rows[0]['lastActivity']);
	}

	public function testManagerThrowsDegradesGracefully(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->setupUser();

		$this->container->method('get')->willThrowException(new \RuntimeException('schema mismatch'));

		$this->assertSame([], $this->provider->list('r', 's', 'uuid', []));
	}
}
