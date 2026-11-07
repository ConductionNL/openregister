<?php

/**
 * Golden record of the Deck and Talk object sources.
 *
 * DeckObjectSourceProvider and TalkObjectSourceProvider: find(), findAll()
 * and count() with no user, services that cannot be resolved or that throw,
 * boards/stacks/rooms whose getters answer, are missing or throw, non-object
 * entries, due dates and last-activity values, and paging. Every container
 * lookup and log line is recorded. The recording lives in
 * fixtures/deck-talk-sources-golden.json; set DECK_TALK_SOURCES_GOLDEN_RECORD=1
 * to write it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Pins the projection of the Deck and Talk object sources.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\DeckObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\TalkObjectSourceProvider;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * A class name the providers can class_exists() on; the container hands out the real fakes.
 */
class DeckTalkSourcesGoldenTestClassStub {
}

foreach (['OCA\\Deck\\Service\\BoardService', 'OCA\\Deck\\Service\\StackService', 'OCA\\Talk\\Manager', 'OCA\\Talk\\Service\\ParticipantService'] as $stubClass) {
	if (class_exists($stubClass) === false) {
		class_alias(DeckTalkSourcesGoldenTestClassStub::class, $stubClass);
	}
}

/**
 * An entity answering getters from a map; a Throwable value is thrown, a missing getter throws.
 */
class DeckTalkSourcesGoldenTestBag {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $answers Getter => answer.
	 */
	public function __construct(private array $answers) {
	}

	/**
	 * Answer a getter.
	 *
	 * @param string       $name The getter.
	 * @param array<mixed> $args The arguments.
	 *
	 * @return mixed The answer.
	 */
	public function __call(string $name, array $args): mixed {
		if (array_key_exists($name, $this->answers) === false) {
			throw new \Error('Call to undefined method '.$name);
		}
		$answer = $this->answers[$name];
		if ($answer instanceof \Throwable) {
			throw $answer;
		}
		if ($answer instanceof \Closure) {
			return $answer(...$args);
		}
		return $answer;
	}
}

/**
 * The Deck and Talk object sources, recorded.
 *
 * @covers \OCA\OpenRegister\Service\ObjectSource\DeckObjectSourceProvider
 * @covers \OCA\OpenRegister\Service\ObjectSource\TalkObjectSourceProvider
 */
class DeckTalkSourcesGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/deck-talk-sources-golden.json';

	/**
	 * Everything observed: container lookups, service calls and log lines.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * A bag.
	 *
	 * @param array<string, mixed> $answers Getter => answer.
	 *
	 * @return DeckTalkSourcesGoldenTestBag The bag.
	 */
	private static function bag(array $answers): DeckTalkSourcesGoldenTestBag {
		return new DeckTalkSourcesGoldenTestBag($answers);
	}//end bag()

	/**
	 * The dependencies: app manager, user session, container, logger.
	 *
	 * @param bool                 $loggedIn Whether a user is logged in.
	 * @param array<string, mixed> $services Class => service, Throwable (thrown) or other value.
	 *
	 * @return array<int, object> The constructor arguments.
	 */
	private function dependencies(bool $loggedIn, array $services): array {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($loggedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}
		$session->method('getUser')->willReturn($user);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class) use ($services): mixed {
				$this->log[] = ['container', $class];
				$service = $services[$class] ?? null;
				if ($service instanceof \Throwable) {
					throw $service;
				}
				return $service;
			}
		);
		$log = &$this->log;
		$logger = new class($log) extends AbstractLogger {
			public function __construct(private array &$log) {
			}
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = ['log', $level, (string)$message];
			}
		};
		return [$apps, $session, $container, $logger];
	}//end dependencies()

	/**
	 * A Deck provider.
	 *
	 * @param bool                 $loggedIn Whether a user is logged in.
	 * @param array<string, mixed> $services Class => service.
	 *
	 * @return DeckObjectSourceProvider The provider.
	 */
	private function deck(bool $loggedIn, array $services): DeckObjectSourceProvider {
		return new DeckObjectSourceProvider(...$this->dependencies($loggedIn, $services));
	}//end deck()

	/**
	 * A Talk provider.
	 *
	 * @param bool                 $loggedIn Whether a user is logged in.
	 * @param array<string, mixed> $services Class => service.
	 *
	 * @return TalkObjectSourceProvider The provider.
	 */
	private function talk(bool $loggedIn, array $services): TalkObjectSourceProvider {
		return new TalkObjectSourceProvider(...$this->dependencies($loggedIn, $services));
	}//end talk()

	/**
	 * Deck services over a board/stack tree.
	 *
	 * @return array<string, mixed> Class => service.
	 */
	private function deckServices(): array {
		$due = new DateTimeImmutable('2026-03-04T05:06:07+00:00');
		$cards = [
			self::bag(['getId' => 11, 'getTitle' => 'First', 'getDescription' => 'Desc', 'getStackId' => '7', 'getDuedate' => $due]),
			'not-an-object',
			self::bag(['getId' => null, 'getTitle' => new RuntimeException('x'), 'getDuedate' => '2026-01-01']),
			self::bag(['getId' => '12', 'getTitle' => 12.5, 'getDescription' => null, 'getStackId' => null, 'getDuedate' => null]),
			self::bag(['getId' => 13, 'getTitle' => 'Third', 'getDescription' => '', 'getStackId' => 8]),
		];
		$stacksByBoard = [
			1 => [
				self::bag(['getCards' => array_slice($cards, 0, 3)]),
				self::bag(['getCards' => new RuntimeException('cards down')]),
				self::bag(['getCards' => 'not-an-array']),
			],
			2 => new RuntimeException('stacks down'),
			3 => [self::bag(['getCards' => array_slice($cards, 3)])],
		];
		$log = &$this->log;
		return [
			'OCA\\Deck\\Service\\BoardService' => self::bag(['findAll' => [
				self::bag(['getId' => 1]),
				self::bag(['getId' => null]),
				self::bag(['getId' => new RuntimeException('no id')]),
				self::bag(['getId' => '2']),
				self::bag(['getId' => 3]),
			]]),
			'OCA\\Deck\\Service\\StackService' => self::bag(['findAll' => static function (int $boardId) use ($stacksByBoard, &$log): array {
				$log[] = ['stacks', $boardId];
				$stacks = $stacksByBoard[$boardId];
				if ($stacks instanceof \Throwable) {
					throw $stacks;
				}
				return $stacks;
			}]),
		];
	}//end deckServices()

	/**
	 * Talk services over a room list.
	 *
	 * @param mixed $participants The participant service, a Throwable or null.
	 *
	 * @return array<string, mixed> Class => service.
	 */
	private function talkServices(mixed $participants): array {
		$activity = new DateTimeImmutable('2026-05-06T07:08:09+00:00');
		$rooms = [
			self::bag(['getToken' => 'tok1', 'getName' => 'Room one', 'getDisplayName' => static fn (string $uid) => 'Shown to '.$uid, 'getType' => '2', 'getLastActivity' => $activity]),
			'not-an-object',
			self::bag(['getToken' => 'tok2', 'getName' => 'Fallback name', 'getDisplayName' => new RuntimeException('x'), 'getType' => null, 'getLastActivity' => '2026']),
			self::bag(['getToken' => new RuntimeException('x'), 'getName' => new RuntimeException('y')]),
		];
		$log = &$this->log;
		return [
			'OCA\\Talk\\Manager' => self::bag(['getRoomsForUser' => static function (string $uid) use ($rooms, &$log): array {
				$log[] = ['rooms', $uid];
				return $rooms;
			}]),
			'OCA\\Talk\\Service\\ParticipantService' => $participants,
		];
	}//end talkServices()

	/**
	 * Flatten what a call returned.
	 *
	 * @param mixed $value An entity, a list of entities, or a scalar.
	 *
	 * @return mixed The flattened value.
	 */
	private function flat(mixed $value): mixed {
		if ($value instanceof ObjectEntity) {
			return [$value->getUuid(), $value->getRegister(), $value->getSchema(), $value->getObject()];
		}
		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->flat($item), $value);
		}
		return $value;
	}//end flat()

	/**
	 * Run every scenario and record it.
	 *
	 * @return array<string, mixed> The recording.
	 */
	private function recordAll(): array {
		$register = new Register();
		$register->setId(21);
		$schema = new Schema();
		$schema->setId(210);
		$counting = self::bag(['getNumberOfActors' => static fn (object $room) => '4']);
		$throwing = self::bag(['getNumberOfActors' => new RuntimeException('count down')]);

		$scenarios = [
			'deck no user' => fn () => $this->deck(false, $this->deckServices())->findAll($register, $schema),
			'deck board service throws' => fn () => $this->deck(true, ['OCA\\Deck\\Service\\BoardService' => new RuntimeException('nope')] + $this->deckServices())->findAll($register, $schema),
			'deck stack service not an object' => fn () => $this->deck(true, ['OCA\\Deck\\Service\\StackService' => 'string'] + $this->deckServices())->findAll($register, $schema),
			'deck boards throw' => fn () => $this->deck(true, ['OCA\\Deck\\Service\\BoardService' => self::bag(['findAll' => new RuntimeException('boards down')])] + $this->deckServices())->find($register, $schema, '11'),
			'deck findAll' => fn () => $this->deck(true, $this->deckServices())->findAll($register, $schema),
			'deck findAll paged' => fn () => $this->deck(true, $this->deckServices())->findAll($register, $schema, ['limit' => '2', 'offset' => '1']),
			'deck count' => fn () => $this->deck(true, $this->deckServices())->count($register, $schema, ['limit' => 3]),
			'deck find hit' => fn () => $this->deck(true, $this->deckServices())->find($register, $schema, '12'),
			'deck find empty id' => fn () => $this->deck(true, $this->deckServices())->find($register, $schema, ''),
			'deck find miss' => fn () => $this->deck(true, $this->deckServices())->find($register, $schema, '99'),
			'talk no user' => fn () => $this->talk(false, $this->talkServices($counting))->findAll($register, $schema),
			'talk manager throws' => fn () => $this->talk(true, ['OCA\\Talk\\Manager' => new RuntimeException('nope')] + $this->talkServices($counting))->findAll($register, $schema),
			'talk rooms throw' => fn () => $this->talk(true, ['OCA\\Talk\\Manager' => self::bag(['getRoomsForUser' => new RuntimeException('rooms down')])] + $this->talkServices($counting))->find($register, $schema, 'tok1'),
			'talk findAll counting' => fn () => $this->talk(true, $this->talkServices($counting))->findAll($register, $schema),
			'talk findAll no participant service' => fn () => $this->talk(true, $this->talkServices(new RuntimeException('no ps')))->findAll($register, $schema),
			'talk findAll participant count throws' => fn () => $this->talk(true, $this->talkServices($throwing))->findAll($register, $schema, ['limit' => 1, 'offset' => 1]),
			'talk count' => fn () => $this->talk(true, $this->talkServices(null))->count($register, $schema),
			'talk find hit' => fn () => $this->talk(true, $this->talkServices($counting))->find($register, $schema, 'tok2'),
			'talk find empty id' => fn () => $this->talk(true, $this->talkServices($counting))->find($register, $schema, ''),
			'talk find miss' => fn () => $this->talk(true, $this->talkServices($counting))->find($register, $schema, 'zzz'),
		];

		$recording = [];
		foreach ($scenarios as $name => $run) {
			$this->log = [];
			$result = $this->flat($run());
			$recording[$name] = ['result' => $result, 'observed' => $this->log];
		}
		return $recording;
	}//end recordAll()

	/**
	 * Every scenario matches the recording.
	 *
	 * @return void
	 */
	public function testMatchesTheGoldenRecord(): void {
		$recording = $this->recordAll();
		if (getenv('DECK_TALK_SOURCES_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($recording, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
		}
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($recording), true));
	}//end testMatchesTheGoldenRecord()
}//end class
