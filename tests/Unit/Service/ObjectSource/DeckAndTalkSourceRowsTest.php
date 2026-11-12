<?php

/**
 * Unit tests for the Deck and Talk object sources — how foreign rows become objects.
 *
 * Deck cards and Talk rooms are read through the other app's services, which
 * the provider resolves from the container only when the class exists. The
 * class names are aliased to an empty stub here (as DeckTalkSourcesGoldenTest
 * does) and the container hands out fakes whose getters answer from a map, so
 * each test decides one branch: a missing service, a failing list, a board
 * without an id, a non-object row, a throwing or missing getter, a date.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses -- test doubles live next to the test.

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\DeckObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\TalkObjectSourceProvider;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * A class name the providers can class_exists() on.
 */
class DeckAndTalkSourceRowsTestClassStub {
}

foreach (['OCA\\Deck\\Service\\BoardService', 'OCA\\Deck\\Service\\StackService', 'OCA\\Talk\\Manager', 'OCA\\Talk\\Service\\ParticipantService'] as $stubClass) {
	if (class_exists($stubClass) === false) {
		class_alias(DeckAndTalkSourceRowsTestClassStub::class, $stubClass);
	}
}

/**
 * An object answering method calls from a map: a Throwable is thrown, a callable is called, a missing method throws.
 */
class DeckAndTalkSourceRowsTestFake {

	/**
	 * Method => answer.
	 *
	 * @param array<string, mixed> $answers
	 */
	public function __construct(private readonly array $answers) {
	}

	public function __call(string $name, array $arguments): mixed {
		if (array_key_exists($name, $this->answers) === false) {
			throw new \Error('Call to undefined method ' . $name . '()');
		}
		$answer = $this->answers[$name];
		if ($answer instanceof Throwable) {
			throw $answer;
		}
		if ($answer instanceof \Closure) {
			return $answer(...$arguments);
		}
		return $answer;
	}
}

final class DeckAndTalkSourceRowsTest extends TestCase {

	private IUserSession&MockObject $session;

	private ContainerInterface&MockObject $container;

	private LoggerInterface&MockObject $logger;

	/**
	 * Container id => service (or a Throwable to throw).
	 *
	 * @var array<string, mixed>
	 */
	private array $services = [];

	/**
	 * Warnings logged, in order.
	 *
	 * @var string[]
	 */
	private array $warnings = [];

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($user);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(function (string $id): mixed {
			$service = $this->services[$id] ?? null;
			if ($service instanceof Throwable) {
				throw $service;
			}
			return $service;
		});
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});
		$this->register = new Register();
		$this->register->setId(1);
		$this->schema = new Schema();
		$this->schema->setId(2);
	}

	private function fake(array $answers): DeckAndTalkSourceRowsTestFake {
		return new DeckAndTalkSourceRowsTestFake($answers);
	}

	private function deck(): DeckObjectSourceProvider {
		return new DeckObjectSourceProvider(app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $this->createMock(IAppManager::class), container: $this->container, logger: $this->logger), userSession: $this->session);
	}

	private function talk(): TalkObjectSourceProvider {
		return new TalkObjectSourceProvider(app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $this->createMock(IAppManager::class), container: $this->container, logger: $this->logger), userSession: $this->session);
	}

	private function rows(array $entities): array {
		return array_map(static fn ($entity) => $entity->getObject(), $entities);
	}

	private function deckServices(array $boards, array $stacksByBoard): void {
		$this->services = [
			'OCA\\Deck\\Service\\BoardService' => $this->fake(['findAll' => $boards]),
			'OCA\\Deck\\Service\\StackService' => $this->fake(['findAll' => function (int $boardId) use ($stacksByBoard) {
				$stacks = $stacksByBoard[$boardId] ?? [];
				if ($stacks instanceof Throwable) {
					throw $stacks;
				}
				return $stacks;
			}]),
		];
	}

	public function testDeckTurnsEveryCardOfEveryBoardIntoARow(): void {
		$due = new DateTimeImmutable('2026-03-04T05:06:07', new DateTimeZone('UTC'));
		$this->deckServices(
			[$this->fake(['getId' => '7']), $this->fake(['getId' => 8])],
			[
				7 => [$this->fake(['getCards' => [
					$this->fake(['getId' => 11, 'getTitle' => 'Fix', 'getDescription' => 'Leak', 'getStackId' => '3', 'getDuedate' => $due]),
					'not a card',
				]])],
				8 => [$this->fake(['getCards' => [$this->fake(['getId' => null, 'getTitle' => 'Bare', 'getDescription' => new RuntimeException('no'), 'getStackId' => null, 'getDuedate' => null])]])],
			]
		);

		$rows = $this->rows($this->deck()->findAll(new SourcedTable(new RegisterSchema($this->register, $this->schema))));

		$this->assertCount(2, $rows);
		$this->assertSame(['11', 'Fix', 'Leak', 3, 7, '2026-03-04T05:06:07+00:00'], [$rows[0]['id'], $rows[0]['title'], $rows[0]['description'], $rows[0]['stackId'], $rows[0]['boardId'], $rows[0]['duedate']]);
		$this->assertSame('', $rows[1]['id'], 'A card without an id gets an empty one.');
		$this->assertSame('', $rows[1]['description'], 'A throwing getter reads as empty text.');
		$this->assertNull($rows[1]['stackId']);
		$this->assertNull($rows[1]['duedate']);
		$this->assertSame(8, $rows[1]['boardId']);
	}

	public function testDeckFindsOneCardById(): void {
		$this->deckServices([$this->fake(['getId' => 7])], [7 => [$this->fake(['getCards' => [$this->fake(['getId' => 11, 'getTitle' => 'A']), $this->fake(['getId' => 12, 'getTitle' => 'B'])]])]]);

		$found = $this->deck()->find(new SourcedTable(new RegisterSchema($this->register, $this->schema)), '12');

		$this->assertSame('12', $found->getUuid());
		$this->assertSame('B', $found->getObject()['title']);
		$this->assertNull($found->getObject()['duedate'], 'A card without a getDuedate getter has no due date.');
		$this->assertNull($this->deck()->find(new SourcedTable(new RegisterSchema($this->register, $this->schema)), '99'));
	}

	public function testDeckSkipsBoardsWithoutAnIdAndStacksWhoseCardsAreUnreadable(): void {
		$this->deckServices(
			[$this->fake(['getId' => null]), $this->fake([]), $this->fake(['getId' => 7])],
			[7 => [
				$this->fake(['getCards' => new RuntimeException('broken')]),
				$this->fake(['getCards' => 'not a list']),
				$this->fake(['getCards' => [$this->fake(['getId' => 1, 'getTitle' => 'kept'])]]),
			]]
		);

		$rows = $this->rows($this->deck()->findAll(new SourcedTable(new RegisterSchema($this->register, $this->schema))));

		$this->assertSame(['kept'], array_column($rows, 'title'));
	}

	public function testDeckLogsAFailingBoardListAndReturnsNothing(): void {
		$this->services = [
			'OCA\\Deck\\Service\\BoardService' => $this->fake(['findAll' => new RuntimeException('db')]),
			'OCA\\Deck\\Service\\StackService' => $this->fake([]),
		];

		$this->assertSame([], $this->deck()->findAll(new SourcedTable(new RegisterSchema($this->register, $this->schema))));
		$this->assertSame(['[ObjectSource:deck-source] could not list boards: db'], $this->warnings);
	}

	public function testDeckLogsAFailingStackListAndKeepsTheOtherBoards(): void {
		$this->deckServices(
			[$this->fake(['getId' => 7]), $this->fake(['getId' => 8])],
			[7 => new RuntimeException('gone'), 8 => [$this->fake(['getCards' => [$this->fake(['getId' => 5, 'getTitle' => 'ok'])]])]]
		);

		$rows = $this->rows($this->deck()->findAll(new SourcedTable(new RegisterSchema($this->register, $this->schema))));

		$this->assertSame(['ok'], array_column($rows, 'title'));
		$this->assertSame(['[ObjectSource:deck-source] could not list stacks for board 7: gone'], $this->warnings);
	}

	public function testDeckWithAnUnresolvableServiceReturnsNothingAndLogsWhy(): void {
		$this->services = [
			'OCA\\Deck\\Service\\BoardService' => new RuntimeException('not registered'),
			'OCA\\Deck\\Service\\StackService' => $this->fake([]),
		];

		$this->assertSame([], $this->deck()->findAll(new SourcedTable(new RegisterSchema($this->register, $this->schema))));
		$this->assertSame(['[ObjectSource:deck-source] could not resolve OCA\\Deck\\Service\\BoardService: not registered'], $this->warnings);
	}

	public function testDeckWithANonObjectServiceReturnsNothing(): void {
		$this->services = [
			'OCA\\Deck\\Service\\BoardService' => $this->fake(['findAll' => [$this->fake(['getId' => 1])]]),
			'OCA\\Deck\\Service\\StackService' => 'not an object',
		];

		$this->assertSame([], $this->deck()->findAll(new SourcedTable(new RegisterSchema($this->register, $this->schema))));
		$this->assertSame([], $this->warnings);
	}

	public function testDeckPagesWithLimitAndOffsetAndCountsAll(): void {
		$cards = array_map(fn (int $i) => $this->fake(['getId' => $i, 'getTitle' => 'c' . $i]), [1, 2, 3, 4]);
		$this->deckServices([$this->fake(['getId' => 7])], [7 => [$this->fake(['getCards' => $cards])]]);

		$page = $this->rows($this->deck()->findAll(new SourcedTable(new RegisterSchema($this->register, $this->schema)), ['limit' => 2, 'offset' => 1]));

		$this->assertSame(['c2', 'c3'], array_column($page, 'title'));
		$this->assertSame(4, $this->deck()->count(new SourcedTable(new RegisterSchema($this->register, $this->schema))));
	}

	public function testTalkTurnsTheUsersRoomsIntoRows(): void {
		$activity = new DateTimeImmutable('2026-05-06T07:08:09', new DateTimeZone('UTC'));
		$this->services = [
			'OCA\\Talk\\Manager' => $this->fake(['getRoomsForUser' => function (string $uid) use ($activity) {
				$this->assertSame('anna', $uid);
				return [
					$this->fake(['getToken' => 'tok1', 'getName' => 'room-1', 'getDisplayName' => fn (string $u) => 'Kamer voor ' . $u, 'getType' => '2', 'getLastActivity' => $activity]),
					'not a room',
					$this->fake(['getToken' => 'tok2', 'getName' => 'fallback-name', 'getDisplayName' => new RuntimeException('old talk'), 'getLastActivity' => 'yesterday']),
				];
			}]),
			'OCA\\Talk\\Service\\ParticipantService' => $this->fake(['getNumberOfActors' => function (object $room) {
				if ($room->getToken() === 'tok2') {
					throw new RuntimeException('no count');
				}
				return '5';
			}]),
		];

		$rows = $this->rows($this->talk()->findAll(new SourcedTable(new RegisterSchema($this->register, $this->schema))));

		$this->assertCount(2, $rows);
		$this->assertSame(
			['tok1', 'room-1', 'Kamer voor anna', 2, 5, '2026-05-06T07:08:09+00:00'],
			[$rows[0]['id'], $rows[0]['name'], $rows[0]['displayName'], $rows[0]['type'], $rows[0]['participantCount'], $rows[0]['lastActivity']]
		);
		$this->assertSame('fallback-name', $rows[1]['displayName'], 'A failing display name falls back to the room name.');
		$this->assertNull($rows[1]['type'], 'A missing getter reads as no type.');
		$this->assertSame(0, $rows[1]['participantCount'], 'A failing count reads as zero.');
		$this->assertNull($rows[1]['lastActivity'], 'A non-date activity is left null.');
	}

	public function testTalkWithoutAParticipantServiceCountsZero(): void {
		$this->services = [
			'OCA\\Talk\\Manager' => $this->fake(['getRoomsForUser' => [$this->fake(['getToken' => 't', 'getName' => 'n', 'getDisplayName' => 'd'])]]),
		];

		$found = $this->talk()->find(new SourcedTable(new RegisterSchema($this->register, $this->schema)), 't');

		$this->assertSame('t', $found->getUuid());
		$this->assertSame(0, $found->getObject()['participantCount']);
		$this->assertNull($found->getObject()['lastActivity'], 'A missing getLastActivity leaves it null.');
	}

	public function testTalkWithoutAManagerReturnsNothing(): void {
		$this->services = [];

		$this->assertSame([], $this->talk()->findAll(new SourcedTable(new RegisterSchema($this->register, $this->schema))));
	}

	public function testTalkLogsAFailingRoomListAndReturnsNothing(): void {
		$this->services = ['OCA\\Talk\\Manager' => $this->fake(['getRoomsForUser' => new RuntimeException('talk down')])];

		$this->assertSame([], $this->talk()->findAll(new SourcedTable(new RegisterSchema($this->register, $this->schema))));
		$this->assertSame(['[ObjectSource:talk-source] could not list rooms: talk down'], $this->warnings);
	}
	public function testWithoutASignedInUserNeitherSourceAsksTheOtherApp(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn(null);
		$this->container->expects($this->never())->method('get');

		$this->assertSame('deck-source', $this->deck()->getId());
		$this->assertSame('talk-source', $this->talk()->getId());
		$this->assertSame([], $this->deck()->findAll(new SourcedTable(new RegisterSchema($this->register, $this->schema))));
		$this->assertSame([], $this->talk()->findAll(new SourcedTable(new RegisterSchema($this->register, $this->schema))));
	}
}
