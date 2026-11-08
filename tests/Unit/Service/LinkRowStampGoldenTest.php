<?php

/**
 * Golden pins for the link rows the Deck, Maps, Polls, Bookmarks, Cospend and Photos link services insert.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\LinkServiceRuntime;
use DateTimeInterface;
use OCA\OpenRegister\Db\BookmarkLinkMapper;
use OCA\OpenRegister\Db\CospendLinkMapper;
use OCA\OpenRegister\Db\DeckLinkMapper;
use OCA\OpenRegister\Db\MapLinkMapper;
use OCA\OpenRegister\Db\PhotoLinkMapper;
use OCA\OpenRegister\Db\PollLinkMapper;
use OCA\OpenRegister\Service\BookmarkLinkService;
use OCA\OpenRegister\Service\BookmarkDraft;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\CospendLinkService;
use OCA\OpenRegister\Service\DeckLinkService;
use OCA\OpenRegister\Service\MapLinkService;
use OCA\OpenRegister\Service\PhotoLinkService;
use OCA\OpenRegister\Service\PollLinkService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\Entity;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

foreach (['OCA\\Deck\\Service\\CardService', 'OCA\\Deck\\Db\\CardMapper', 'OCA\\Maps\\Service\\FavoritesService', 'OCA\\Bookmarks\\Db\\BookmarkMapper', 'OCA\\Cospend\\Service\\ProjectService'] as $stubClass) {
	if (class_exists($stubClass) === false) {
		$pos = strrpos($stubClass, '\\');
		eval('namespace ' . substr($stubClass, 0, $pos) . '; class ' . substr($stubClass, $pos + 1) . ' {}');
	}
}

if (class_exists('OCA\\Bookmarks\\Db\\Bookmark') === false) {
	eval('namespace OCA\\Bookmarks\\Db; class Bookmark { public array $values = []; public function __call($name, $args) { $this->values[$name] = $args[0] ?? null; return null; } }');
}

/**
 * Golden for the link rows the link-by-id flows insert: every field of the
 * inserted entity plus the fields marked updated, compared with
 * tests/Unit/Service/fixtures/link-row-stamp-golden.json.
 * Run with UPDATE_GOLDEN=1 to re-record.
 *
 * @covers \OCA\OpenRegister\Service\DeckLinkService
 * @covers \OCA\OpenRegister\Service\MapLinkService
 * @covers \OCA\OpenRegister\Service\PollLinkService
 * @covers \OCA\OpenRegister\Service\BookmarkLinkService
 * @covers \OCA\OpenRegister\Service\CospendLinkService
 * @covers \OCA\OpenRegister\Service\PhotoLinkService
 * @covers \OCA\OpenRegister\Service\LinkTarget
 * @uses   \OCA\OpenRegister\Db\DeckLink
 * @uses   \OCA\OpenRegister\Db\MapLink
 * @uses   \OCA\OpenRegister\Db\PollLink
 * @uses   \OCA\OpenRegister\Db\BookmarkLink
 * @uses   \OCA\OpenRegister\Db\CospendEntryKey
 * @uses   \OCA\OpenRegister\Db\CospendLink
 * @uses   \OCA\OpenRegister\Db\PhotoLink
 * @uses   \OCA\OpenRegister\AppInfo\ClassAliasMap
 * @uses \OCA\OpenRegister\Service\BookmarkDraft
 */
class LinkRowStampGoldenTest extends TestCase {

	/**
	 * The moment the test started: stamps from then on read as now.
	 *
	 * @var int
	 */
	private int $startedAt;

	/**
	 * Note when the test starts.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->startedAt = time();
	}//end setUp()

	/**
	 * Schema ids to link under.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function schemas(): array {
		return [
			'schema 4' => [4],
			'no schema' => [0],
		];
	}//end schemas()

	/**
	 * Linking a Deck card pins the inserted row.
	 *
	 * @param int $schemaId Schema id.
	 *
	 * @return void
	 *
	 * @dataProvider schemas
	 */
	public function testLinkCardGolden(int $schemaId): void {
		$mapper = $this->mapper(DeckLinkMapper::class, 'findByObjectAndCard');
		$card = new class {
			public function getStackId(): int {
				return 7;
			}

			public function getTitle(): string {
				return 'Card';
			}
		};
		$cards = new class($card) {
			public function __construct(private object $card) {
			}

			public function find(int $id): object {
				return $this->card;
			}
		};
		$cardMapper = new class {
			public function findBoardId(int $id): int {
				return 12;
			}
		};
		$service = new DeckLinkService(
			$mapper,
			$this->apps(),
			$this->session('alice'),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
			$this->container(['OCA\\Deck\\Service\\CardService' => $cards, 'OCA\\Deck\\Db\\CardMapper' => $cardMapper])
		);

		$this->assertGolden(fn () => $this->entityFields($service->linkCard((new LinkTarget('obj-1', 3))->inSchema($schemaId), 21)));
	}//end testLinkCardGolden()

	/**
	 * Linking a Maps favorite pins the inserted row.
	 *
	 * @param int $schemaId Schema id.
	 *
	 * @return void
	 *
	 * @dataProvider schemas
	 */
	public function testLinkPoiGolden(int $schemaId): void {
		$favorites = new class {
			public function getFavoriteFromDB(int $id, string $uid): array {
				return ['name' => 'Dam', 'category' => 'square', 'lat' => '52.37', 'lng' => '4.89', 'comment' => null];
			}
		};
		$service = new MapLinkService(
			$this->mapper(MapLinkMapper::class, 'findByObjectAndFavorite'),
			$this->container(['OCA\\Maps\\Service\\FavoritesService' => $favorites]),
			$this->createMock(IURLGenerator::class),
			new LinkServiceRuntime(
				$this->apps(),
				$this->session('alice'),
				$this->createMock(LoggerInterface::class)
			)
		);

		$this->assertGolden(fn () => $this->entityFields($service->linkPoi((new LinkTarget('obj-1', 3))->inSchema($schemaId), 9)));
	}//end testLinkPoiGolden()

	/**
	 * Linking a poll pins the inserted row.
	 *
	 * @param int $schemaId Schema id.
	 *
	 * @return void
	 *
	 * @dataProvider schemas
	 */
	public function testLinkPollGolden(int $schemaId): void {
		$row = ['id' => 5, 'title' => 'When?', 'type' => 'datePoll', 'expire' => 1767225600, 'owner' => 'bob', 'deleted' => 0];
		$service = new PollLinkService(
			$this->mapper(PollLinkMapper::class, 'findByObjectAndPoll'),
			$this->db($row),
			new LinkServiceRuntime(
				$this->apps(),
				$this->session('alice'),
				$this->createMock(LoggerInterface::class)
			)
		);

		$this->assertGolden(fn () => $this->entityFields($service->linkPoll((new LinkTarget('obj-1', 3))->inSchema($schemaId), 5)));
	}//end testLinkPollGolden()

	/**
	 * Linking an existing bookmark pins the inserted row.
	 *
	 * @param int $schemaId Schema id.
	 *
	 * @return void
	 *
	 * @dataProvider schemas
	 */
	public function testLinkBookmarkGolden(int $schemaId): void {
		$bookmark = new class {
			public function getUserId(): string {
				return 'alice';
			}

			public function toArray(): array {
				return ['id' => 8, 'title' => 'Docs', 'url' => 'https://x.nl', 'description' => 'd', 'added' => 1767225600];
			}

			public function getTags(): array {
				return ['a', 'or:hidden', ' b '];
			}
		};
		$service = $this->bookmarkService($bookmark);

		$this->assertGolden(fn () => $this->entityFields($service->linkBookmark((new LinkTarget('obj-1', 3))->inSchema($schemaId), 8)));
	}//end testLinkBookmarkGolden()

	/**
	 * Creating and linking a bookmark pins the inserted row.
	 *
	 * @param int $schemaId Schema id.
	 *
	 * @return void
	 *
	 * @dataProvider schemas
	 */
	public function testCreateAndLinkBookmarkGolden(int $schemaId): void {
		$service = $this->bookmarkService(null);

		$this->assertGolden(
			fn () => $this->entityFields($service->createAndLinkBookmark((new LinkTarget('obj-1', 3))->inSchema($schemaId), (new BookmarkDraft(' Docs ', ' https://x.nl '))->withDetails(null, ['a', 'or:x'])))
		);
	}//end testCreateAndLinkBookmarkGolden()

	/**
	 * Cospend scenarios: flow, schema id.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function cospendScenarios(): array {
		return [
			'project' => ['project', 4],
			'bill' => ['bill', 4],
			'created project' => ['create', 4],
			'created project, no schema' => ['create', 0],
		];
	}//end cospendScenarios()

	/**
	 * Linking Cospend entries pins the inserted row.
	 *
	 * @param string $flow     Which link flow runs.
	 * @param int    $schemaId Schema id.
	 *
	 * @return void
	 *
	 * @dataProvider cospendScenarios
	 */
	public function testCospendLinkGolden(string $flow, int $schemaId): void {
		$projects = new class {
			public function createProject(...$args): string {
				return 'p-1';
			}
		};
		$row = ['id' => 'p-1', 'name' => 'Trip', 'currency_name' => 'EUR', 'what' => 'Dinner', 'amount' => '12.5', 'project_id' => 'p-1'];
		$service = new CospendLinkService(
			$this->mapper(CospendLinkMapper::class, 'findDuplicate'),
			$this->container(['OCA\\Cospend\\Service\\ProjectService' => $projects]),
			$this->apps(),
			$this->session('alice'),
			$this->db($row),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertGolden(
			fn () => $this->entityFields(
				match ($flow) {
					'project' => $service->linkProject((new LinkTarget('obj-1', 3))->inSchema($schemaId), 'p-1'),
					'bill' => $service->linkBill((new LinkTarget('obj-1', 3))->inSchema($schemaId), 'p-1', 6),
					default => $service->createAndLinkProject((new LinkTarget('obj-1', 3))->inSchema($schemaId), 'Trip', ''),
				}
			)
		);
	}//end testCospendLinkGolden()

	/**
	 * Linking and creating Photos albums pins the inserted row.
	 *
	 * @param int $schemaId Schema id.
	 *
	 * @return void
	 *
	 * @dataProvider schemas
	 */
	public function testPhotoLinkGolden(int $schemaId): void {
		$album = new class {
			public function getId(): int {
				return 31;
			}

			public function getTitle(): string {
				return 'Summer 2026';
			}

			public function getLastAddedPhoto(): int {
				return 1767225600;
			}
		};
		$albums = new class($album) {
			public function __construct(private object $album) {
			}

			public function get(int $id): object {
				return $this->album;
			}

			public function create(string $uid, string $name): object {
				return $this->album;
			}

			public function getForAlbumIdAndUserWithFiles(...$args): array {
				return [1, 2, 3];
			}
		};
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/apps/photos/');
		$service = new PhotoLinkService(
			$this->mapper(PhotoLinkMapper::class, 'findByObjectAndAlbum'),
			$this->container(['OCA\\Photos\\Album\\AlbumMapper' => $albums]),
			$urls,
			new LinkServiceRuntime(
				$this->apps(),
				$this->session('alice'),
				$this->createMock(LoggerInterface::class)
			)
		);

		$this->assertGolden(
			fn () => [
				'linked' => $this->entityFields($service->linkAlbum((new LinkTarget('obj-1', 3))->inSchema($schemaId), 31)),
				'created' => $this->entityFields($service->createAndLinkAlbum((new LinkTarget('obj-2', 3))->inSchema($schemaId), ' Summer 2026 ')),
			]
		);
	}//end testPhotoLinkGolden()

	/**
	 * A bookmark service whose Bookmarks mapper finds the given bookmark.
	 *
	 * @param object|null $bookmark Bookmark returned by find().
	 *
	 * @return BookmarkLinkService
	 */
	private function bookmarkService(?object $bookmark): BookmarkLinkService {
		$bookmarks = new class($bookmark) {
			public function __construct(private ?object $bookmark) {
			}

			public function find(int $id): ?object {
				return $this->bookmark;
			}

			public function insertOrUpdate(object $bookmark): object {
				return new class {
					public function getId(): int {
						return 55;
					}
				};
			}
		};
		return new BookmarkLinkService(
			$this->mapper(BookmarkLinkMapper::class, 'findByObjectAndBookmark'),
			$this->apps(),
			$this->session('alice'),
			$this->createMock(LoggerInterface::class),
			$this->container(['\\OCA\\Bookmarks\\Db\\BookmarkMapper' => $bookmarks])
		);
	}//end bookmarkService()

	/**
	 * A link mapper that finds no existing row and returns what it inserts.
	 *
	 * @param class-string $class  Mapper class.
	 * @param string       $finder Duplicate lookup method.
	 *
	 * @return object
	 */
	private function mapper(string $class, string $finder): object {
		$mapper = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods([$finder, 'insert'])->getMock();
		$mapper->method($finder)->willReturn(null);
		$mapper->method('insert')->willReturnArgument(0);
		return $mapper;
	}//end mapper()

	/**
	 * An app manager reporting every app enabled.
	 *
	 * @return IAppManager
	 */
	private function apps(): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		return $apps;
	}//end apps()

	/**
	 * A database whose queries all return the given row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return IDBConnection
	 */
	private function db(array $row): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use ($row) {
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturn($row);
				$result->method('fetchAll')->willReturn([['user_id' => 'a'], ['user_id' => 'b']]);
				$result->method('fetchOne')->willReturn('3');
				$qb = $this->createMock(IQueryBuilder::class);
				foreach (['select', 'selectDistinct', 'from', 'where', 'andWhere', 'setMaxResults'] as $method) {
					$qb->method($method)->willReturnSelf();
				}

				$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
				$qb->method('func')->willReturn($this->createMock(IFunctionBuilder::class));
				$qb->method('executeQuery')->willReturn($result);
				return $qb;
			}
		);
		return $db;
	}//end db()

	/**
	 * Run the action and compare its result (or exception) with the recorded golden.
	 *
	 * @param callable $action The action.
	 *
	 * @return void
	 */
	private function assertGolden(callable $action): void {
		try {
			$actual = ['result' => $action()];
		} catch (Throwable $e) {
			$actual = ['error' => [get_class($e), $e->getMessage(), $e->getCode()]];
		}

		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE), true);
		$file = __DIR__ . '/fixtures/link-row-stamp-golden.json';
		$name = $this->name() . ' ' . $this->dataName();
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
	}//end assertGolden()

	/**
	 * The entity's fields and its updated-field set; a time within a minute of now reads "now".
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return array<string, mixed>
	 */
	private function entityFields(Entity $entity): array {
		$fields = [];
		foreach (array_keys($entity->getFieldTypes()) as $field) {
			$value = $entity->{'get' . ucfirst($field)}();
			if ($value instanceof DateTimeInterface) {
				$stampedNow = $value->getTimestamp() >= $this->startedAt && $value->getTimestamp() <= time();
				$value = $stampedNow === true ? 'now' : $value->format('c');
			}

			$fields[$field] = $value;
		}

		$updated = array_keys($entity->getUpdatedFields());
		sort($updated);
		return ['fields' => $fields, 'updated' => $updated];
	}//end entityFields()

	/**
	 * A session for the given user.
	 *
	 * @param string $uid User id.
	 *
	 * @return IUserSession
	 */
	private function session(string $uid): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A container serving the given services.
	 *
	 * @param array<string, object> $services Services by id.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(fn (string $id) => isset($services[$id]));
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services) {
				if (isset($services[$id]) === false) {
					throw new RuntimeException('no ' . $id);
				}

				return $services[$id];
			}
		);
		return $container;
	}//end container()
}//end class
