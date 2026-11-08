<?php

/**
 * Characterisation golden for DeckLinkService and DeckCardService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use Error;
use Exception;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\DeckLinkMapperInterface;
use OCA\OpenRegister\Service\CardDraft;
use OCA\OpenRegister\Service\DeckCardService;
use OCA\OpenRegister\Service\DeckLinkService;
use OCA\OpenRegister\Service\LinkTarget;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

foreach (['OCA\\Deck\\Service\\CardService', 'OCA\\Deck\\Db\\CardMapper', 'OCA\\Deck\\Service\\BoardService', 'OCA\\Deck\\Service\\StackService'] as $stubClass) {
	if (class_exists($stubClass) === false) {
		$pos = strrpos($stubClass, '\\');
		eval('namespace ' . substr($stubClass, 0, $pos) . '; class ' . substr($stubClass, $pos + 1) . ' {}');
	}
}

/**
 * Pins results, thrown exceptions, Deck and mapper calls and log lines of the
 * public methods of DeckLinkService and DeckCardService, compared with
 * tests/Unit/Service/fixtures/deck-services-golden.json.
 * Run with UPDATE_GOLDEN=1 to re-record.
 *
 * @covers \OCA\OpenRegister\Service\DeckLinkService
 * @covers \OCA\OpenRegister\Service\DeckCardService
 * @uses   \OCA\OpenRegister\Db\DeckLink
 * @uses   \OCA\OpenRegister\Service\CardDraft
 * @uses   \OCA\OpenRegister\Service\DeckRowMapper
 * @uses   \OCA\OpenRegister\Service\LinkTarget
 */
class DeckServicesGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/deck-services-golden.json';

	private array $calls = [];

	/**
	 * An object answering each configured method with a value, a callable's result, or by throwing.
	 */
	public function bag(string $name, array $answers): object {
		return new class($this, $name, $answers) {
			public function __construct(private $test, private string $name, private array $answers) {
			}

			public function __call(string $method, array $args): mixed {
				$this->test->record($this->name . '.' . $method . '(' . json_encode($args) . ')');
				if (array_key_exists($method, $this->answers) === false) {
					throw new Error('Call to undefined method ' . $method);
				}

				$answer = $this->answers[$method];
				if ($answer instanceof Throwable) {
					throw $answer;
				}

				if ($answer instanceof \Closure) {
					return $answer(...$args);
				}

				return $answer;
			}
		};
	}//end bag()

	private function cards(): array {
		$label = $this->bag('label', ['getId' => '3', 'getTitle' => 'Urgent', 'getColor' => 'red']);
		$badLabel = $this->bag('badlabel', ['getId' => new RuntimeException('x'), 'getTitle' => new RuntimeException('x'), 'getColor' => 'blue']);
		$alice = $this->bag('assign', ['getParticipant' => 'alice', 'getTypeString' => 'user']);
		$group = $this->bag('assign', ['getParticipant' => 'staff', 'getTypeString' => 'group']);
		return [
			7 => $this->bag('card7', [
				'getStackId' => '5', 'getTitle' => 'Fix bug', 'getDuedate' => new DateTime('2026-03-01T10:00:00+00:00'),
				'getLabels' => [$label, $badLabel], 'getAssignedUsers' => [$alice, $group],
				'getRelatedBoard' => $this->bag('board', ['getTitle' => 'Board A']), 'getRelatedStack' => null,
			]),
			8 => $this->bag('card8', [
				'getStackId' => new RuntimeException('no stack'), 'getTitle' => new RuntimeException('no title'),
				'getDuedate' => '2026-01-01', 'getLabels' => 'x', 'getAssignedUsers' => new RuntimeException('no users'),
				'getRelatedBoard' => new RuntimeException('no board'), 'getRelatedStack' => $this->bag('stack', ['getTitle' => 'Doing']),
			]),
			9 => $this->bag('card9', ['getStackId' => 2, 'getTitle' => 'Bare', 'getDuedate' => null, 'getLabels' => [], 'getAssignedUsers' => []]),
			10 => 'not-an-object',
		];
	}//end cards()

	private function world(array $over): array {
		return $over + [
			'uid' => 'alice',
			'enabled' => true,
			'services' => ['CardService', 'CardMapper', 'BoardService', 'StackService'],
			'existing' => false,
			'deleted' => 1,
			'linked' => [],
			'boardIds' => [7 => 11, 9 => null, 21 => 12],
			'boardIdThrows' => false,
			'createThrows' => false,
			'createdId' => 21,
			'boards' => 'default',
			'stacks' => 'default',
			'findLink' => true,
			'boardAccess' => true,
			'route' => '/index.php/apps/deck/',
		];
	}//end world()

	private function cases(): array {
		$rows = [
			['cardId' => 7, 'boardId' => 11, 'cardTitle' => 'cached 7'],
			['cardId' => 8, 'boardId' => 0, 'cardTitle' => 'cached 8'],
			['cardId' => 99, 'boardId' => 11, 'cardTitle' => 'gone'],
			['cardId' => 10, 'boardId' => null, 'cardTitle' => 'weird'],
			['cardId' => 0, 'boardId' => 11, 'cardTitle' => 'zero'],
			['cardId' => null, 'boardId' => 11, 'cardTitle' => 'null card'],
		];
		$draft = (new CardDraft('New card', 5))->onBoard(11)->withDetails('Body', '2026-04-01T00:00:00+00:00');
		return [
			['link', 'available', 'isDeckAvailable', [], []],
			['link', 'link no user', 'linkCard', [7], ['uid' => null]],
			['link', 'link duplicate', 'linkCard', [7], ['existing' => true]],
			['link', 'link no card service', 'linkCard', [7], ['services' => []]],
			['link', 'link find throws', 'linkCard', [99], []],
			['link', 'link find null', 'linkCard', [55], []],
			['link', 'link ok widened', 'linkCard', [7], []],
			['link', 'link ok broken getters', 'linkCard', [8], ['services' => ['CardService']]],
			['link', 'link ok bare', 'linkCard', [9], ['boardIdThrows' => true]],
			['link', 'unlink ok', 'unlinkCard', ['obj-1', 7], []],
			['link', 'unlink missing', 'unlinkCard', ['obj-1', 7], ['deleted' => 0]],
			['link', 'linked rows', 'getLinkedCards', ['obj-1'], ['linked' => $rows]],
			['link', 'linked no card service', 'getLinkedCards', ['obj-1'], ['linked' => $rows, 'services' => []]],
			['link', 'create no user', 'createAndLinkCard', [$draft], ['uid' => null]],
			['link', 'create no service', 'createAndLinkCard', [$draft], ['services' => []]],
			['link', 'create fails', 'createAndLinkCard', [$draft], ['createThrows' => true]],
			['link', 'create ok', 'createAndLinkCard', [$draft], []],
			['link', 'create ok board fallback', 'createAndLinkCard', [$draft], ['createdId' => 22]],
			['link', 'boards no service', 'getAvailableBoards', [], ['services' => []]],
			['link', 'boards no user', 'getAvailableBoards', [], ['uid' => null]],
			['link', 'boards throws', 'getAvailableBoards', [], ['boards' => 'throws']],
			['link', 'boards not array', 'getAvailableBoards', [], ['boards' => 'string']],
			['link', 'boards', 'getAvailableBoards', [], []],
			['link', 'stacks no service', 'getStacksForBoard', [11], ['services' => []]],
			['link', 'stacks throws', 'getStacksForBoard', [11], ['stacks' => 'throws']],
			['link', 'stacks not array', 'getStacksForBoard', [11], ['stacks' => 'string']],
			['link', 'stacks', 'getStacksForBoard', [11], []],
			['card', 'cards for object', 'getCardsForObject', ['obj-1'], ['linked' => $rows]],
			['card', 'cards for object route throws', 'getCardsForObject', ['obj-1'], ['linked' => $rows, 'route' => 'throws']],
			['card', 'cards for object no service', 'getCardsForObject', ['obj-1'], ['linked' => $rows, 'services' => []]],
			['card', 'loc no user', 'linkOrCreateCard', ['obj-1', 3, ['cardId' => 7]], ['uid' => null]],
			['card', 'loc nothing', 'linkOrCreateCard', ['obj-1', 3, ['boardId' => 11]], []],
			['card', 'loc nothing at all', 'linkOrCreateCard', ['obj-1', 3, ['cardId' => 0, 'stackId' => 4]], []],
			['card', 'loc card ok', 'linkOrCreateCard', ['obj-1', 3, ['cardId' => '7', 'boardId' => 1, 'stackId' => 1]], []],
			['card', 'loc card no mapper', 'linkOrCreateCard', ['obj-1', 3, ['cardId' => 9]], ['services' => ['CardService']]],
			['card', 'loc card missing', 'linkOrCreateCard', ['obj-1', 3, ['cardId' => 99]], []],
			['card', 'loc card no service', 'linkOrCreateCard', ['obj-1', 3, ['cardId' => 7]], ['services' => []]],
			['card', 'loc card duplicate', 'linkOrCreateCard', ['obj-1', 3, ['cardId' => 7]], ['existing' => true]],
			['card', 'loc create ok', 'linkOrCreateCard', ['obj-1', 3, ['boardId' => '11', 'stackId' => '5', 'title' => 'T', 'description' => 'D']], []],
			['card', 'loc create defaults', 'linkOrCreateCard', ['obj-1', 3, ['boardId' => 11, 'stackId' => 5]], []],
			['card', 'loc create fails', 'linkOrCreateCard', ['obj-1', 3, ['boardId' => 11, 'stackId' => 5]], ['createThrows' => true]],
			['card', 'loc create no service', 'linkOrCreateCard', ['obj-1', 3, ['boardId' => 11, 'stackId' => 5]], ['services' => []]],
			['card', 'card unlink ok', 'unlinkCard', [4], []],
			['card', 'card unlink missing', 'unlinkCard', [4], ['findLink' => false]],
			['card', 'board objects', 'getObjectsForBoard', [11], ['linked' => $rows]],
			['card', 'board objects refused', 'getObjectsForBoard', [11], ['linked' => $rows, 'boardAccess' => false]],
			['card', 'delete for object', 'deleteLinksForObject', ['obj-1'], ['deleted' => 3]],
		];
	}//end cases()

	public function testDeckServicesMatchGolden(): void {
		$actual = [];
		foreach ($this->cases() as [$kind, $name, $method, $args, $over]) {
			$this->calls = [];
			$w = $this->world($over);
			$service = $this->makeService($kind, $w);
			if (in_array($method, ['linkCard', 'createAndLinkCard'], true) === true) {
				array_unshift($args, (new LinkTarget('obj-1', 3))->inSchema(4));
			}

			try {
				$result = $this->normalise($service->$method(...$args));
			} catch (Throwable $e) {
				$result = ['throws' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
			}

			$actual[$name] = ['result' => $result, 'calls' => $this->calls];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testDeckServicesMatchGolden()

	public function record(string $call): void {
		$this->calls[] = $this->normalise($call);
	}//end record()

	public function normalise(mixed $value): mixed {
		if ($value instanceof DeckLink) {
			$fields = [];
			foreach (['ObjectUuid', 'RegisterId', 'SchemaId', 'BoardId', 'StackId', 'CardId', 'CardTitle', 'DueDate', 'Labels', 'Assignees', 'LinkedBy', 'LinkedAt'] as $field) {
				$fields[$field] = $this->normalise($value->{'get' . $field}());
			}

			$fields['updated'] = array_keys($value->getUpdatedFields());
			return $fields;
		}

		if ($value instanceof DateTime) {
			if (abs($value->getTimestamp() - time()) < 3600) {
				return 'NOW';
			}

			return $value->format(DATE_ATOM);
		}

		if (is_array($value) === true) {
			return array_map(fn ($v) => $this->normalise($v), $value);
		}

		if (is_string($value) === true) {
			return (string)preg_replace_callback(
				'/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d/',
				fn (array $m) => abs(strtotime($m[0]) - time()) < 3600 ? 'NOW' : $m[0],
				$value
			);
		}

		return $value;
	}//end normalise()

	private function linkRow(array $spec): DeckLink {
		$link = new DeckLink();
		$link->setCardId($spec['cardId']);
		$link->setBoardId($spec['boardId']);
		$link->setCardTitle($spec['cardTitle']);
		$link->setLinkedAt(new DateTime('@1700000000'));
		$link->resetUpdatedFields();
		return $link;
	}//end linkRow()

	private function deckServices(array $w): array {
		$cards = $this->cards();
		$created = $w['createdId'];
		$services = [
			'OCA\\Deck\\Service\\CardService' => $this->bag('CardService', [
				'find' => function ($id) use ($cards) {
					if ($id === 99) {
						throw new RuntimeException('card ' . $id . ' gone');
					}

					return $cards[$id] ?? null;
				},
				'create' => $w['createThrows'] === true ? new Exception('deck refused') : $this->bag('created', ['getId' => $created]),
				'update' => null,
			]),
			'OCA\\Deck\\Db\\CardMapper' => $this->bag('CardMapper', [
				'findBoardId' => $w['boardIdThrows'] === true ? new RuntimeException('mapper down') : fn ($id) => $w['boardIds'][$id] ?? null,
			]),
			'OCA\\Deck\\Service\\BoardService' => $this->bag('BoardService', [
				'findAll' => match ($w['boards']) {
					'throws' => new RuntimeException('boards down'),
					'string' => 'nope',
					default => [
						$this->bag('b1', ['getId' => 11, 'getTitle' => 'Board A']),
						$this->bag('b2', ['getId' => new RuntimeException('x'), 'getTitle' => 'never']),
						$this->bag('b3', ['getId' => '12', 'getTitle' => new RuntimeException('x')]),
						$this->bag('b4', ['getId' => 0, 'getTitle' => 'zero']),
					],
				},
				'find' => $w['boardAccess'] === true ? null : new RuntimeException('no permission'),
			]),
			'OCA\\Deck\\Service\\StackService' => $this->bag('StackService', [
				'findAll' => match ($w['stacks']) {
					'throws' => new RuntimeException('stacks down'),
					'string' => 'nope',
					default => [
						$this->bag('s1', ['getId' => 5, 'getTitle' => 'Todo']),
						$this->bag('s2', ['getId' => 6, 'getTitle' => new RuntimeException('x')]),
						$this->bag('s3', ['getId' => new RuntimeException('x')]),
						$this->bag('s4', ['getId' => null, 'getTitle' => 'none']),
					],
				},
			]),
		];
		$out = [];
		foreach ($w['services'] as $short) {
			foreach ($services as $id => $service) {
				if (str_ends_with($id, '\\' . $short) === true) {
					$out[$id] = $service;
				}
			}
		}

		return $out;
	}//end deckServices()

	private function makeService(string $kind, array $w): object {
		$services = $this->deckServices($w);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services) {
				$this->record('container.get(' . $id . ')');
				if (isset($services[$id]) === false) {
					throw new RuntimeException('no ' . $id);
				}

				return $services[$id];
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn($w['enabled']);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($w['uid'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($w['uid']);
		}

		$session->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid) {
				if ($uid !== 'alice') {
					return null;
				}

				$named = $this->createMock(IUser::class);
				$named->method('getDisplayName')->willReturn('Alice A');
				return $named;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(fn (string $m) => $this->record($level . ' ' . $m));
		}

		$links = $this->createMock(DeckLinkMapperInterface::class);
		$links->method('findByObjectAndCard')->willReturnCallback(
			function (string $uuid, int $id) use ($w) {
				$this->record('findByObjectAndCard(' . $uuid . ', ' . $id . ')');
				return $w['existing'] === true ? $this->linkRow(['cardId' => $id, 'boardId' => 1, 'cardTitle' => 'dup']) : null;
			}
		);
		$links->method('deleteByObjectAndCard')->willReturnCallback(fn (string $uuid, int $id) => $this->record('deleteByObjectAndCard(' . $uuid . ', ' . $id . ')') ?? $w['deleted']);
		$links->method('deleteByObjectUuid')->willReturnCallback(fn (string $uuid) => $this->record('deleteByObjectUuid(' . $uuid . ')') ?? $w['deleted']);
		$links->method('findByObjectUuid')->willReturnCallback(fn (string $uuid) => $this->record('findByObjectUuid(' . $uuid . ')') ?? array_map(fn ($s) => $this->linkRow($s), $w['linked']));
		$links->method('findByBoardId')->willReturnCallback(fn (int $id) => $this->record('findByBoardId(' . $id . ')') ?? array_map(fn ($s) => $this->linkRow($s), $w['linked']));
		$links->method('find')->willReturnCallback(
			function (int $id) use ($w) {
				$this->record('find(' . $id . ')');
				if ($w['findLink'] === false) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('none');
				}

				return $this->linkRow(['cardId' => 7, 'boardId' => 11, 'cardTitle' => 'x']);
			}
		);
		$links->method('delete')->willReturnCallback(fn ($e) => $this->record('delete(' . json_encode($this->normalise($e)) . ')') ?? $e);
		$links->method('insert')->willReturnCallback(fn ($e) => $this->record('insert(' . json_encode($this->normalise($e)) . ')') ?? $e);
		if ($kind === 'link') {
			return new DeckLinkService($links, $appManager, $session, $users, $logger, $container);
		}

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			function (string $route) use ($w) {
				$this->record('linkToRoute(' . $route . ')');
				if ($w['route'] === 'throws') {
					throw new RuntimeException('no route');
				}

				return $w['route'];
			}
		);
		return new DeckCardService($links, $appManager, $session, $logger, $urls, $users, $container);
	}//end makeService()
}//end class
