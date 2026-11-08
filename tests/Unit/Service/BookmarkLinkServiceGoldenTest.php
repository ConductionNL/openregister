<?php

/**
 * Characterisation golden for BookmarkLinkService.
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
use OCA\OpenRegister\Db\BookmarkLink;
use OCA\OpenRegister\Db\BookmarkLinkMapperInterface;
use OCA\OpenRegister\Service\BookmarkDraft;
use OCA\OpenRegister\Service\BookmarkLinkService;
use OCA\OpenRegister\Service\LinkTarget;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

foreach (['OCA\\Bookmarks\\Db\\BookmarkMapper', 'OCA\\Bookmarks\\Db\\TagMapper'] as $stubClass) {
	if (class_exists($stubClass) === false) {
		$pos = strrpos($stubClass, '\\');
		eval('namespace ' . substr($stubClass, 0, $pos) . '; class ' . substr($stubClass, $pos + 1) . ' {}');
	}
}

if (class_exists('OCA\\Bookmarks\\Db\\Bookmark') === false) {
	eval('namespace OCA\\Bookmarks\\Db; class Bookmark { public array $values = []; public function __call($name, $args) { $this->values[$name] = $args[0] ?? null; return null; } }');
}

if (class_exists('OCA\\Bookmarks\\QueryParameters') === false) {
	eval('namespace OCA\\Bookmarks; class QueryParameters { public array $values = []; public function __call($name, $args) { $this->values[$name] = $args[0] ?? null; return $this; } }');
}

/**
 * Pins results, thrown exceptions, mapper calls and log lines of every public
 * method of BookmarkLinkService, compared with
 * tests/Unit/Service/fixtures/bookmark-link-service-golden.json.
 * Run with UPDATE_GOLDEN=1 to re-record.
 *
 * @covers \OCA\OpenRegister\Service\BookmarkLinkService
 * @uses   \OCA\OpenRegister\Db\BookmarkLink
 * @uses   \OCA\OpenRegister\Service\BookmarkDraft
 * @uses   \OCA\OpenRegister\Service\LinkTarget
 */
class BookmarkLinkServiceGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/bookmark-link-service-golden.json';

	private array $calls = [];

	/**
	 * World defaults; each case overrides some keys.
	 */
	private static function world(array $over): array {
		return $over + [
			'uid' => 'alice',
			'enabled' => true,
			'mapper' => true,
			'tagMapper' => true,
			'existing' => false,
			'linked' => [],
			'deleted' => 1,
			'bookmarks' => [],
			'findAllThrows' => false,
			'insertId' => 21,
			'insertThrows' => null,
			'tagsByBookmark' => [],
			'tagThrows' => false,
			'updateThrows' => false,
		];
	}//end world()

	private static function bookmarks(): array {
		return [
			8 => ['id' => 8, 'userId' => 'alice', 'title' => 'Docs', 'url' => 'https://d.nl', 'description' => 'D', 'added' => 1700000000, 'tags' => ['x', ' y ', 'or:obj', '', 5, 'x']],
			9 => ['id' => 9, 'userId' => 'bob', 'title' => 'Bob', 'url' => 'https://b.nl'],
			10 => ['id' => 10, 'userId' => 'alice', 'url' => 'https://notitle.nl', 'added' => 0],
			11 => ['id' => 11, 'userId' => 'alice', 'title' => 'Plain', 'url' => 'https://p.nl', 'added' => -4, 'array' => true],
			12 => ['userId' => 'alice', 'title' => 'NoArrId', 'url' => 'https://n.nl', 'getId' => 12, 'tags' => 'notarray'],
		];
	}//end bookmarks()

	private static function cases(): array {
		$all = self::bookmarks();
		$tagged = [8 => ['t1', 'or:hidden'], 10 => ['t2'], 12 => ['t3']];
		$stale = ['bookmarkId' => 8, 'linkedAt' => null];
		$fresh = ['bookmarkId' => 8, 'linkedAt' => 'recent'];
		$old = ['bookmarkId' => 10, 'linkedAt' => 'old'];
		$gone = ['bookmarkId' => 77, 'linkedAt' => null];
		$draft = ['title' => ' Docs ', 'url' => ' https://x.nl ', 'description' => null, 'tags' => ['a', 'or:x', ' a ', 3]];
		return [
			'available yes' => ['isBookmarksAvailable', [], []],
			'available no' => ['isBookmarksAvailable', [], ['enabled' => false]],
			'link no user' => ['linkBookmark', [8], ['uid' => null]],
			'link duplicate' => ['linkBookmark', [8], ['existing' => true]],
			'link unavailable' => ['linkBookmark', [8], ['enabled' => false]],
			'link no mapper' => ['linkBookmark', [8], ['mapper' => false]],
			'link find throws' => ['linkBookmark', [99], ['bookmarks' => $all]],
			'link other user' => ['linkBookmark', [9], ['bookmarks' => $all]],
			'link ok' => ['linkBookmark', [8], ['bookmarks' => $all]],
			'link no title tag lookup' => ['linkBookmark', [10], ['bookmarks' => $all, 'tagsByBookmark' => $tagged]],
			'link tag lookup throws' => ['linkBookmark', [10], ['bookmarks' => $all, 'tagThrows' => true]],
			'link no tag mapper' => ['linkBookmark', [10], ['bookmarks' => $all, 'tagMapper' => false]],
			'link toArray negative added' => ['linkBookmark', [11], ['bookmarks' => $all]],
			'link getId fallback' => ['linkBookmark', [12], ['bookmarks' => $all, 'tagsByBookmark' => $tagged]],
			'unlink ok' => ['unlinkBookmark', [], []],
			'unlink missing' => ['unlinkBookmark', [], ['deleted' => 0]],
			'linked empty' => ['getLinkedBookmarks', [], []],
			'linked unavailable' => ['getLinkedBookmarks', [], ['enabled' => false, 'linked' => [$stale]]],
			'linked no user' => ['getLinkedBookmarks', [], ['uid' => null, 'linked' => [$stale]]],
			'linked fresh' => ['getLinkedBookmarks', [], ['linked' => [$fresh], 'bookmarks' => $all]],
			'linked refresh' => ['getLinkedBookmarks', [], ['linked' => [$stale, $old, $gone, $fresh], 'bookmarks' => $all, 'tagsByBookmark' => $tagged]],
			'linked refresh update fails' => ['getLinkedBookmarks', [], ['linked' => [$stale], 'bookmarks' => $all, 'updateThrows' => true]],
			'available none' => ['getAvailableBookmarks', [null, null], ['enabled' => false]],
			'available no user' => ['getAvailableBookmarks', [null, null], ['uid' => null]],
			'available no mapper' => ['getAvailableBookmarks', [null, null], ['mapper' => false]],
			'available all' => ['getAvailableBookmarks', [null, null], ['bookmarks' => $all, 'tagsByBookmark' => $tagged]],
			'available empty filters' => ['getAvailableBookmarks', ['', []], ['bookmarks' => $all]],
			'available filtered' => ['getAvailableBookmarks', ['doc', ['k' => 'a', 'b']], ['bookmarks' => [8 => $all[8]]]],
			'available findAll throws' => ['getAvailableBookmarks', ['doc', null], ['findAllThrows' => true]],
			'create no user' => ['createAndLinkBookmark', [$draft], ['uid' => null]],
			'create unavailable' => ['createAndLinkBookmark', [$draft], ['enabled' => false]],
			'create empty title' => ['createAndLinkBookmark', [['title' => '  '] + $draft], []],
			'create empty url' => ['createAndLinkBookmark', [['url' => ' '] + $draft], []],
			'create no mapper' => ['createAndLinkBookmark', [$draft], ['mapper' => false]],
			'create zero id' => ['createAndLinkBookmark', [$draft], ['insertId' => 0]],
			'create insert exception' => ['createAndLinkBookmark', [$draft], ['insertThrows' => 'exception']],
			'create insert error' => ['createAndLinkBookmark', [$draft], ['insertThrows' => 'error']],
			'create ok' => ['createAndLinkBookmark', [$draft], []],
			'create ok long title' => ['createAndLinkBookmark', [['title' => str_repeat('é', 600), 'description' => 'desc', 'tags' => []] + $draft], []],
			'create no tag mapper' => ['createAndLinkBookmark', [$draft], ['tagMapper' => false]],
			'create existing link' => ['createAndLinkBookmark', [$draft], ['existing' => true]],
		];
	}//end cases()

	public function testBookmarkLinkServiceMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$method, $args, $over]) {
			$this->calls = [];
			$service = $this->makeService(self::world($over));
			$target = (new LinkTarget('obj-1', 3))->inSchema(4);
			$callArgs = match ($method) {
				'linkBookmark' => [$target, $args[0]],
				'unlinkBookmark' => ['obj-1', 8],
				'getLinkedBookmarks' => ['obj-1'],
				'createAndLinkBookmark' => [$target, (new BookmarkDraft($args[0]['title'], $args[0]['url']))->withDetails($args[0]['description'], $args[0]['tags'])],
				default => $args,
			};
			try {
				$result = $this->normalise($service->$method(...$callArgs));
			} catch (Throwable $e) {
				$result = ['throws' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
			}

			$actual[$name] = ['result' => $result, 'calls' => $this->calls];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testBookmarkLinkServiceMatchesGolden()

	public function record(string $call): void {
		$this->calls[] = $call;
	}//end record()

	/**
	 * Replace timestamps taken from the clock by a marker so the golden is clock-free.
	 */
	public function normalise(mixed $value): mixed {
		if ($value instanceof BookmarkLink) {
			$fields = [];
			foreach (['ObjectUuid', 'RegisterId', 'SchemaId', 'BookmarkId', 'Title', 'Url', 'Description', 'Tags', 'AddedAt', 'LinkedBy', 'LinkedAt'] as $field) {
				$fields[$field] = $this->normalise($value->{'get' . $field}());
			}

			$fields['updated'] = array_keys($value->getUpdatedFields());
			return $fields;
		}

		if ($value instanceof DateTime) {
			if (abs($value->getTimestamp() - time()) < 3600) {
				return 'NOW';
			}

			return $value->format('U');
		}

		if (is_array($value) === true) {
			return array_map(fn ($v) => $this->normalise($v), $value);
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d\d-\d\dT/', $value) === 1) {
			$time = strtotime($value);
			if (abs($time - time()) < 3600) {
				return 'NOW';
			}

			return (string)$time;
		}

		return $value;
	}//end normalise()

	public function bookmarkObject(array $spec): object {
		if (($spec['array'] ?? false) === true) {
			return new class($spec) {
				public function __construct(private array $spec) {
				}

				public function toArray(): array {
					return $this->spec;
				}

				public function getUserId(): string {
					return $this->spec['userId'];
				}
			};
		}

		$bookmark = new #[\AllowDynamicProperties] class($spec) {
			public function __construct(private array $spec) {
			}

			public function getUserId(): string {
				return $this->spec['userId'];
			}

			public function getTags(): mixed {
				return $this->spec['tags'] ?? null;
			}

			public function getId(): int {
				return $this->spec['getId'] ?? 0;
			}
		};
		foreach ($spec as $key => $val) {
			if (in_array($key, ['getId', 'tags', 'userId'], true) === false) {
				$bookmark->$key = $val;
			}
		}

		return $bookmark;
	}//end bookmarkObject()

	public function linkRow(array $spec): BookmarkLink {
		$link = new BookmarkLink();
		$link->setBookmarkId($spec['bookmarkId']);
		$link->setTitle('cached');
		if ($spec['linkedAt'] === 'recent') {
			$link->setLinkedAt(new DateTime('-10 seconds'));
		}

		if ($spec['linkedAt'] === 'old') {
			$link->setLinkedAt(new DateTime('@1600000000'));
		}

		$link->resetUpdatedFields();
		return $link;
	}//end linkRow()

	private function makeService(array $w): BookmarkLinkService {
		$test = $this;
		$bookmarkMapper = new class($test, $w) {
			public function __construct(private $test, private array $w) {
			}

			public function find(int $id): object {
				$this->test->record('find(' . $id . ')');
				if (isset($this->w['bookmarks'][$id]) === false) {
					throw new RuntimeException('no bookmark ' . $id);
				}

				return $this->test->bookmarkObject($this->w['bookmarks'][$id]);
			}

			public function findAll(string $uid, object $params): array {
				$this->test->record('findAll(' . $uid . ', ' . json_encode($params->values) . ')');
				if ($this->w['findAllThrows'] === true) {
					throw new RuntimeException('list down');
				}

				return array_values(array_map(fn ($b) => $this->test->bookmarkObject($b), $this->w['bookmarks']));
			}

			public function insertOrUpdate(object $bookmark): object {
				$this->test->record('insertOrUpdate(' . json_encode($bookmark->values, JSON_UNESCAPED_UNICODE) . ')');
				if ($this->w['insertThrows'] === 'exception') {
					throw new RuntimeException('insert refused', 7);
				}

				if ($this->w['insertThrows'] === 'error') {
					throw new Error('insert broke');
				}

				return new class($this->w['insertId']) {
					public function __construct(private int $id) {
					}

					public function getId(): int {
						return $this->id;
					}
				};
			}
		};
		$tagMapper = new class($test, $w) {
			public function __construct(private $test, private array $w) {
			}

			public function findByBookmark(int $id): array {
				$this->test->record('findByBookmark(' . $id . ')');
				if ($this->w['tagThrows'] === true) {
					throw new RuntimeException('tags down');
				}

				return $this->w['tagsByBookmark'][$id] ?? [];
			}

			public function addTo(array $tags, int $id): void {
				$this->test->record('addTo(' . json_encode($tags) . ', ' . $id . ')');
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($w, $bookmarkMapper, $tagMapper) {
				$this->record('container.get(' . $id . ')');
				if (str_ends_with($id, 'BookmarkMapper') === true && $w['mapper'] === true) {
					return $bookmarkMapper;
				}

				if (str_ends_with($id, 'TagMapper') === true && $w['tagMapper'] === true) {
					return $tagMapper;
				}

				throw new RuntimeException('no ' . $id);
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			function (string $app) use ($w) {
				$this->record('isEnabledForUser(' . $app . ')');
				return $w['enabled'];
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($w['uid'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($w['uid']);
		}

		$session->method('getUser')->willReturn($user);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(fn (string $m) => $this->record('warning ' . $m));
		$logger->method('debug')->willReturnCallback(fn (string $m) => $this->record('debug ' . $m));
		$links = $this->createMock(BookmarkLinkMapperInterface::class);
		$links->method('findByObjectAndBookmark')->willReturnCallback(
			function (string $uuid, int $id) use ($w) {
				$this->record('findByObjectAndBookmark(' . $uuid . ', ' . $id . ')');
				if ($w['existing'] === true) {
					return $this->linkRow(['bookmarkId' => $id, 'linkedAt' => 'old']);
				}

				return null;
			}
		);
		$links->method('deleteByObjectAndBookmark')->willReturnCallback(
			function (string $uuid, int $id) use ($w) {
				$this->record('deleteByObjectAndBookmark(' . $uuid . ', ' . $id . ')');
				return $w['deleted'];
			}
		);
		$links->method('findByObjectUuid')->willReturnCallback(
			function (string $uuid) use ($w) {
				$this->record('findByObjectUuid(' . $uuid . ')');
				return array_map(fn (array $spec) => $this->linkRow($spec), $w['linked']);
			}
		);
		$links->method('insert')->willReturnCallback(
			function ($entity) {
				$this->record('insert(' . json_encode($this->normalise($entity), JSON_UNESCAPED_UNICODE) . ')');
				return $entity;
			}
		);
		$links->method('update')->willReturnCallback(
			function ($entity) use ($w) {
				$this->record('update(' . json_encode($this->normalise($entity), JSON_UNESCAPED_UNICODE) . ')');
				if ($w['updateThrows'] === true) {
					throw new RuntimeException('update down');
				}

				return $entity;
			}
		);
		return new BookmarkLinkService($links, $appManager, $session, $logger, $container);
	}//end makeService()
}//end class
