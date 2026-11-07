<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Db\TalkLinkMapper;
use OCA\OpenRegister\Service\TalkLinkService;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * A class name the service can class_exists() on; the container hands out the fakes.
 */
class TalkLinkServiceRefactorGoldenTestClassStub {
}

foreach (['OCA\\Talk\\Manager', 'OCA\\Talk\\Service\\ParticipantService', 'OCA\\Talk\\Service\\RoomService'] as $talkLinkGoldenStub) {
	if (class_exists($talkLinkGoldenStub) === false) {
		class_alias(TalkLinkServiceRefactorGoldenTestClassStub::class, $talkLinkGoldenStub);
	}
}

/**
 * Answers every call from a map: a Throwable is thrown, a missing answer throws.
 * Only __call, so method_exists() is false for every getter, as on a Talk entity.
 */
class TalkLinkServiceRefactorGoldenTestBag {

	/**
	 * Every call any bag answered.
	 *
	 * @var list<array<int, mixed>>
	 */
	public static array $log = [];

	/**
	 * Constructor.
	 *
	 * @param string               $tag     Name in the log.
	 * @param array<string, mixed> $answers Method => answer.
	 */
	public function __construct(public string $tag, public array $answers) {
	}

	/**
	 * Answer a call.
	 *
	 * @param string $name The method.
	 * @param array  $args The arguments.
	 *
	 * @return mixed
	 */
	public function answer(string $name, array $args): mixed {
		self::$log[] = [$this->tag, $name, array_map(fn ($a) => is_object($a) === true ? ($a->tag ?? get_class($a)) : $a, $args)];
		if (array_key_exists($name, $this->answers) === false) {
			throw new RuntimeException($this->tag . ' has no ' . $name);
		}

		$answer = $this->answers[$name];
		if ($answer instanceof Throwable) {
			throw $answer;
		}

		return $answer;
	}

	/**
	 * Magic getter.
	 *
	 * @param string $name The method.
	 * @param array  $args The arguments.
	 *
	 * @return mixed
	 */
	public function __call(string $name, array $args): mixed {
		return $this->answer($name, $args);
	}
}

/**
 * A room on a Talk release that has the optional accessors.
 */
class TalkLinkServiceRefactorGoldenTestRoom extends TalkLinkServiceRefactorGoldenTestBag {
	/** @return mixed */
	public function getDisplayName(string $uid) {
		return $this->answer('getDisplayName', [$uid]);
	}

	/** @return mixed */
	public function getDescription() {
		return $this->answer('getDescription', []);
	}

	/** @return mixed */
	public function getLastActivity() {
		return $this->answer('getLastActivity', []);
	}

	/** @return mixed */
	public function getLastMessage() {
		return $this->answer('getLastMessage', []);
	}
}

/**
 * A Talk Manager with every lookup.
 */
class TalkLinkServiceRefactorGoldenTestManager extends TalkLinkServiceRefactorGoldenTestBag {
	/** @return mixed */
	public function getRoomForUserByToken(string $token, string $uid) {
		return $this->answer('getRoomForUserByToken', [$token, $uid]);
	}

	/** @return mixed */
	public function getRoomByToken(string $token) {
		return $this->answer('getRoomByToken', [$token]);
	}

	/** @return mixed */
	public function createRoom($type, $name, $owner) {
		return $this->answer('createRoom', [$type, $name, $owner]);
	}

	/** @return mixed */
	public function getRoomsForUser($uid, $a, $b) {
		return $this->answer('getRoomsForUser', [$uid, $a, $b]);
	}
}

/**
 * A Talk Manager of an old release: raw token lookup only.
 */
class TalkLinkServiceRefactorGoldenTestOldManager extends TalkLinkServiceRefactorGoldenTestBag {
	/** @return mixed */
	public function getRoomByToken(string $token) {
		return $this->answer('getRoomByToken', [$token]);
	}
}

/**
 * Talk's ParticipantService and RoomService in one.
 */
class TalkLinkServiceRefactorGoldenTestServices extends TalkLinkServiceRefactorGoldenTestBag {
	/** @return mixed */
	public function addUsers($room, $users) {
		return $this->answer('addUsers', [$room, $users]);
	}

	/** @return mixed */
	public function getNumberOfActors($room) {
		return $this->answer('getNumberOfActors', [$room]);
	}

	/** @return mixed */
	public function setDescription($room, $description) {
		return $this->answer('setDescription', [$room, $description]);
	}
}

/**
 * A last message with every accessor.
 */
class TalkLinkServiceRefactorGoldenTestComment extends TalkLinkServiceRefactorGoldenTestBag {
	/** @return mixed */
	public function getMessage() {
		return $this->answer('getMessage', []);
	}

	/** @return mixed */
	public function getActorType() {
		return $this->answer('getActorType', []);
	}

	/** @return mixed */
	public function getActorId() {
		return $this->answer('getActorId', []);
	}

	/** @return mixed */
	public function getCreationDateTime() {
		return $this->answer('getCreationDateTime', []);
	}
}

/**
 * Pins what the TalkLinkService room operations return and do to the mapper, Talk
 * and the log, per scenario, against fixtures/talk-link-refactor-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite it. linkedAt (the wall clock) is masked; cache
 * ages are far past or far future so staleness never depends on the run date.
 *
 * @covers \OCA\OpenRegister\Service\TalkLinkService
 */
class TalkLinkServiceRefactorGoldenTest extends TestCase {

	/**
	 * Collaborator calls.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * A fully featured room.
	 *
	 * @param string $tag     Log name.
	 * @param array  $answers Overrides.
	 *
	 * @return TalkLinkServiceRefactorGoldenTestRoom
	 */
	private static function room(string $tag, array $answers = []): TalkLinkServiceRefactorGoldenTestRoom {
		$comment = new TalkLinkServiceRefactorGoldenTestComment('comment', [
			'getMessage' => 'Hallo',
			'getActorType' => 'users',
			'getActorId' => 'bob',
			'getCreationDateTime' => new DateTimeImmutable('2026-01-02T03:04:05+00:00'),
		]);
		return new TalkLinkServiceRefactorGoldenTestRoom($tag, $answers + [
			'getId' => '12',
			'getName' => 'Raw [or:abc-1] name',
			'getDisplayName' => 'Shown name [or:abc-1]',
			'getType' => '2',
			'getToken' => 'tok-' . $tag,
			'getDescription' => '  ',
			'getLastActivity' => new DateTimeImmutable('2026-02-03T04:05:06+01:00'),
			'getLastMessage' => $comment,
		]);
	}

	/**
	 * A room exposing only magic getters.
	 *
	 * @param string $tag     Log name.
	 * @param array  $answers Answers.
	 *
	 * @return TalkLinkServiceRefactorGoldenTestBag
	 */
	private static function bareRoom(string $tag, array $answers): TalkLinkServiceRefactorGoldenTestBag {
		return new TalkLinkServiceRefactorGoldenTestBag($tag, $answers);
	}

	/**
	 * A stored link row.
	 *
	 * @param string      $token        Room token.
	 * @param string|null $lastActivity Cache age proxy.
	 * @param string|null $linkedAt     Link time.
	 *
	 * @return TalkLink
	 */
	private static function link(string $token, ?string $lastActivity, ?string $linkedAt): TalkLink {
		$link = new TalkLink();
		$link->setId(7);
		$link->setObjectUuid('abc-1');
		$link->setRegisterId(1);
		$link->setSchemaId(2);
		$link->setRoomToken($token);
		$link->setRoomName('Old name');
		$link->setLinkedBy('carol');
		if ($lastActivity !== null) {
			$link->setLastActivity(new DateTime($lastActivity));
		}

		if ($linkedAt !== null) {
			$link->setLinkedAt(new DateTime($linkedAt));
		}

		$link->resetUpdatedFields();
		return $link;
	}

	/**
	 * Describe a link for the record.
	 *
	 * @param TalkLink $link The link.
	 *
	 * @return array
	 */
	private static function describe(TalkLink $link): array {
		$json = $link->jsonSerialize();
		if ($json['linkedAt'] !== null && $link->getLinkedBy() !== 'carol') {
			$json['linkedAt'] = '<NOW>';
		}

		return ['json' => $json, 'lastMessageData' => $link->getLastMessageData(), 'updated' => array_keys($link->getUpdatedFields())];
	}

	/**
	 * Build the service.
	 *
	 * @param array $opt Scenario options: talk (bool), user (?string), services (class => object|Throwable),
	 *                   existing (?TalkLink), links (list<TalkLink>), updateThrows (bool).
	 *
	 * @return TalkLinkService
	 */
	private function service(array $opt): TalkLinkService {
		$mapper = $this->getMockBuilder(TalkLinkMapper::class)->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid', 'findByObjectAndRoom', 'insert', 'update'])->getMock();
		$mapper->method('findByObjectAndRoom')->willReturn($opt['existing'] ?? null);
		$mapper->method('findByObjectUuid')->willReturn($opt['links'] ?? []);
		$mapper->method('insert')->willReturnCallback(
			function (TalkLink $link) {
				$this->calls[] = ['insert', self::describe($link)];
				return $link;
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (TalkLink $link) use ($opt) {
				$this->calls[] = ['update', self::describe($link)];
				if (($opt['updateThrows'] ?? false) === true) {
					throw new RuntimeException('db gone');
				}
				return $link;
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($opt['talk'] ?? true);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if (array_key_exists('user', $opt) === false || $opt['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($opt['user'] ?? 'alice');
		}
		$session->method('getUser')->willReturn($user);

		$services = ($opt['services'] ?? []);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services) {
				$this->calls[] = ['container', $id];
				$service = ($services[$id] ?? new RuntimeException('no ' . $id));
				if ($service instanceof Throwable) {
					throw $service;
				}
				return $service;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($text) => 'T:' . $text);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message) use ($level) {
					$this->calls[] = [$level, $message];
				}
			);
		}

		return new TalkLinkService($mapper, $container, $apps, $session, $l10n, $logger, $this->createMock(SchemaMapperInterface::class));
	}//end service()

	/**
	 * Talk services where every lookup works.
	 *
	 * @param object $manager The manager.
	 *
	 * @return array
	 */
	private static function talk(object $manager): array {
		$services = new TalkLinkServiceRefactorGoldenTestServices('services', ['addUsers' => null, 'getNumberOfActors' => '5', 'setDescription' => null]);
		return [
			'OCA\\Talk\\Manager' => $manager,
			'OCA\\Talk\\Service\\ParticipantService' => $services,
			'OCA\\Talk\\Service\\RoomService' => $services,
		];
	}

	/**
	 * Scenario table: name => closure(self): mixed.
	 *
	 * @return array<string, array{\Closure}>
	 */
	public static function scenarios(): array {
		$manager = fn (array $answers) => new TalkLinkServiceRefactorGoldenTestManager('manager', $answers);
		$room = fn (string $tag, array $answers = []) => self::room($tag, $answers);
		$bare = fn (string $tag, array $answers) => self::bareRoom($tag, $answers);
		$boom = new RuntimeException('boom');
		$created = $room('new', ['getDescription' => 'Over de zaak', 'getType' => '3']);
		$plainServices = new TalkLinkServiceRefactorGoldenTestBag('plain', []);

		return [
			'link: no user' => [fn (self $t) => $t->service(['user' => null])->linkRoom('abc-1', 1, 2, 'tok')],
			'link: duplicate' => [fn (self $t) => $t->service(['existing' => self::link('tok', null, null)])->linkRoom('abc-1', 1, 2, 'tok')],
			'link: talk disabled' => [fn (self $t) => $t->service(['talk' => false])->linkRoom('abc-1', 1, 2, 'tok')],
			'link: manager unresolvable' => [fn (self $t) => $t->service([])->linkRoom('abc-1', 1, 2, 'tok')],
			'link: room not found anywhere' => [fn (self $t) => $t->service(['services' => self::talk($manager(['getRoomForUserByToken' => $boom, 'getRoomByToken' => $boom]))])->linkRoom('abc-1', 1, 2, 'tok')],
			'link: full room' => [fn (self $t) => $t->service(['services' => self::talk($manager(['getRoomForUserByToken' => $room('r1')]))])->linkRoom('abc-1', 1, 2, 'tok')],
			'link: fallback lookup, bare room' => [fn (self $t) => $t->service(['services' => ['OCA\\Talk\\Manager' => $manager(['getRoomForUserByToken' => $boom, 'getRoomByToken' => $bare('b1', ['getId' => $boom, 'getName' => '[or:x] Bare', 'getType' => '6'])])]])->linkRoom('abc-1', 1, 2, 'tok')],
			'link: old manager, room odd' => [fn (self $t) => $t->service(['services' => ['OCA\\Talk\\Manager' => new TalkLinkServiceRefactorGoldenTestOldManager('old', ['getRoomByToken' => $room('r2', ['getName' => $boom, 'getDisplayName' => '', 'getType' => $boom, 'getDescription' => 'Beschrijving', 'getLastActivity' => 'not a date', 'getLastMessage' => 'none'])]), 'OCA\\Talk\\Service\\ParticipantService' => $plainServices]])->linkRoom('abc-1', 1, 2, 'tok')],
			'link: display name throws, types' => [fn (self $t) => $t->service(['services' => self::talk($manager(['getRoomForUserByToken' => $room('r3', ['getDisplayName' => $boom, 'getType' => '1', 'getLastActivity' => $boom, 'getLastMessage' => $boom])]))])->linkRoom('abc-1', 1, 2, 'tok')],
			'link: type 4, actor count throws, bare comment' => [fn (self $t) => $t->service(['services' => ['OCA\\Talk\\Manager' => $manager(['getRoomForUserByToken' => $room('r4', ['getType' => '4', 'getDescription' => $boom, 'getLastMessage' => $bare('c', [])])]), 'OCA\\Talk\\Service\\ParticipantService' => new TalkLinkServiceRefactorGoldenTestServices('svc', ['getNumberOfActors' => $boom])]])->linkRoom('abc-1', 1, 2, 'tok')],
			'link: type 9, comment date throws' => [fn (self $t) => $t->service(['services' => self::talk($manager(['getRoomForUserByToken' => $room('r5', ['getType' => '9', 'getLastMessage' => new TalkLinkServiceRefactorGoldenTestComment('cm', ['getMessage' => 5, 'getActorType' => 'guests', 'getActorId' => 'g1', 'getCreationDateTime' => $boom])])]))])->linkRoom('abc-1', 1, 2, 'tok')],
			'link: comment date not a date' => [fn (self $t) => $t->service(['services' => self::talk($manager(['getRoomForUserByToken' => $room('r6', ['getLastMessage' => new TalkLinkServiceRefactorGoldenTestComment('cm', ['getMessage' => '', 'getActorType' => '', 'getActorId' => '', 'getCreationDateTime' => '2026'])])]))])->linkRoom('abc-1', 1, 2, 'tok')],
			'rooms: none linked' => [fn (self $t) => $t->service([])->getLinkedRooms('abc-1')],
			'rooms: no talk, stale rows kept' => [fn (self $t) => $t->service(['talk' => false, 'links' => [self::link('a', null, null)]])->getLinkedRooms('abc-1')],
			'rooms: no user' => [fn (self $t) => $t->service(['user' => null, 'services' => self::talk($manager([])), 'links' => [self::link('a', null, null)]])->getLinkedRooms('abc-1')],
			'rooms: refresh stale, keep fresh' => [fn (self $t) => $t->service(['services' => self::talk($manager(['getRoomForUserByToken' => $room('r7')])), 'links' => [self::link('stale', '2000-01-01T00:00:00+00:00', null), self::link('fresh', '2999-01-01T00:00:00+00:00', null), self::link('neverActive', null, '2000-01-01T00:00:00+00:00'), self::link('linkedFuture', null, '2999-01-01T00:00:00+00:00')]])->getLinkedRooms('abc-1')],
			'rooms: room gone' => [fn (self $t) => $t->service(['services' => self::talk(new TalkLinkServiceRefactorGoldenTestBag('nomethods', [])), 'links' => [self::link('gone', null, null)]])->getLinkedRooms('abc-1')],
			'rooms: update throws' => [fn (self $t) => $t->service(['updateThrows' => true, 'services' => self::talk($manager(['getRoomForUserByToken' => $room('r8')])), 'links' => [self::link('x', null, null)]])->getLinkedRooms('abc-1')],
			'create: no user' => [fn (self $t) => $t->service(['user' => null])->createAndLinkRoom('abc-1', 1, 2, 'Zaak', null, 2)],
			'create: bad type' => [fn (self $t) => $t->service([])->createAndLinkRoom('abc-1', 1, 2, 'Zaak', null, 1)],
			'create: no talk' => [fn (self $t) => $t->service(['talk' => false])->createAndLinkRoom('abc-1', 1, 2, 'Zaak', null, 3)],
			'create: createRoom throws' => [fn (self $t) => $t->service(['services' => self::talk($manager(['createRoom' => $boom]))])->createAndLinkRoom('abc-1', 1, 2, 'Zaak', 'D', 2)],
			'create: createRoom not object' => [fn (self $t) => $t->service(['services' => self::talk($manager(['createRoom' => 'x']))])->createAndLinkRoom('abc-1', 1, 2, 'Zaak', 'D', 2)],
			'create: full' => [fn (self $t) => $t->service(['services' => self::talk($manager(['createRoom' => $created]))])->createAndLinkRoom('abc-1', 1, 2, 'Zaak', 'Over de zaak', 3)],
			'create: empty description, services missing' => [fn (self $t) => $t->service(['services' => ['OCA\\Talk\\Manager' => $manager(['createRoom' => $bare('nb', ['getToken' => 'tk', 'getName' => '', 'getId' => '3', 'getType' => $boom])])]])->createAndLinkRoom('abc-1', 1, 2, 'Zaak', '', 2)],
			'create: services without methods' => [fn (self $t) => $t->service(['services' => ['OCA\\Talk\\Manager' => $manager(['createRoom' => $room('nm')]), 'OCA\\Talk\\Service\\RoomService' => $plainServices, 'OCA\\Talk\\Service\\ParticipantService' => $plainServices]])->createAndLinkRoom('abc-1', 1, 2, 'Zaak', 'D', 2)],
			'create: description and invite throw' => [fn (self $t) => $t->service(['services' => ['OCA\\Talk\\Manager' => $manager(['createRoom' => $room('th')]), 'OCA\\Talk\\Service\\RoomService' => new TalkLinkServiceRefactorGoldenTestServices('rs', ['setDescription' => $boom]), 'OCA\\Talk\\Service\\ParticipantService' => new TalkLinkServiceRefactorGoldenTestServices('ps', ['addUsers' => $boom, 'getNumberOfActors' => 1])]])->createAndLinkRoom('abc-1', 1, 2, 'Zaak', 'D', 2)],
			'create: token throws' => [fn (self $t) => $t->service(['services' => self::talk($manager(['createRoom' => $room('tt', ['getToken' => $boom])]))])->createAndLinkRoom('abc-1', 1, 2, 'Zaak', null, 2)],
			'create: token empty' => [fn (self $t) => $t->service(['services' => self::talk($manager(['createRoom' => $room('te', ['getToken' => ''])]))])->createAndLinkRoom('abc-1', 1, 2, 'Zaak', null, 2)],
			'available: no talk' => [fn (self $t) => $t->service(['talk' => false])->getAvailableRoomsForUser()],
			'available: no user' => [fn (self $t) => $t->service(['user' => null])->getAvailableRoomsForUser()],
			'available: no manager' => [fn (self $t) => $t->service([])->getAvailableRoomsForUser()],
			'available: rooms throw' => [fn (self $t) => $t->service(['services' => self::talk($manager(['getRoomsForUser' => $boom]))])->getAvailableRoomsForUser()],
			'available: rooms not array' => [fn (self $t) => $t->service(['services' => self::talk($manager(['getRoomsForUser' => 'x']))])->getAvailableRoomsForUser()],
			'available: mixed rooms' => [fn (self $t) => $t->service(['services' => self::talk($manager(['getRoomsForUser' => [
				$room('a1'),
				$bare('a2', ['getToken' => $boom]),
				$bare('a3', ['getToken' => '']),
				$bare('a4', ['getToken' => 't4', 'getName' => 'Plain [or:q]', 'getType' => '3']),
				$bare('a5', ['getToken' => 't5', 'getName' => $boom, 'getType' => $boom]),
				$room('a6', ['getType' => '4']),
				$room('a7', ['getType' => '6']),
				$room('a8', ['getDisplayName' => $boom, 'getName' => 'Other']),
				$room('a9', ['getDisplayName' => '']),
			]]))])->getAvailableRoomsForUser()],
			'available: search' => [fn (self $t) => $t->service(['services' => self::talk($manager(['getRoomsForUser' => [$room('s1'), $room('s2', ['getDisplayName' => 'ÉÉN Zaak'])]]))])->getAvailableRoomsForUser('  één ')],
			'available: blank search' => [fn (self $t) => $t->service(['services' => self::talk($manager(['getRoomsForUser' => [$room('b1')]]))])->getAvailableRoomsForUser('   ')],
			'available: no participant service' => [fn (self $t) => $t->service(['services' => ['OCA\\Talk\\Manager' => $manager(['getRoomsForUser' => [$room('p1')]])]])->getAvailableRoomsForUser()],
			'available: actor count throws' => [fn (self $t) => $t->service(['services' => ['OCA\\Talk\\Manager' => $manager(['getRoomsForUser' => [$room('p2')]]), 'OCA\\Talk\\Service\\ParticipantService' => new TalkLinkServiceRefactorGoldenTestServices('ps', ['getNumberOfActors' => $boom])]])->getAvailableRoomsForUser()],
		];
	}//end scenarios()

	/**
	 * @dataProvider scenarios
	 *
	 * @param \Closure $run The scenario.
	 *
	 * @return void
	 */
	public function testGolden(\Closure $run): void {
		$this->calls = [];
		TalkLinkServiceRefactorGoldenTestBag::$log = [];
		try {
			$value = $run($this);
			if ($value instanceof TalkLink) {
				$value = self::describe($value);
			}
			$result = ['return' => $value];
		} catch (\Exception $e) {
			$result = ['exception' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
		}

		$result['calls'] = $this->calls;
		$result['talk'] = TalkLinkServiceRefactorGoldenTestBag::$log;
		$actual = json_decode(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);

		$file = __DIR__ . '/fixtures/talk-link-refactor-golden.json';
		$name = $this->dataName();
		$all = [];
		if (is_file($file) === true) {
			$all = json_decode(file_get_contents($file), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertSame($all[$name], $actual);
	}//end testGolden()
}//end class
