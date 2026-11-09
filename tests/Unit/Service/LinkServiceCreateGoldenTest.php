<?php

/**
 * Golden pins for the create/link entry points of the Email, Share, Deck and Map link services.
 *
 * Each scenario records what the service writes (the inserted link row, the
 * share setters, the calls into Deck and Maps) or the exception it raises,
 * and compares it with tests/Unit/Service/fixtures/link-service-create-golden.json.
 * Run with UPDATE_GOLDEN=1 to re-record.
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
use OCA\OpenRegister\Db\DeckLinkMapper;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Db\MapLinkMapper;
use OCA\OpenRegister\Service\DeckLinkService;
use OCA\OpenRegister\Service\CardDraft;
use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\EmailMessageRef;
use OCA\OpenRegister\Service\MapLinkService;
use OCA\OpenRegister\Service\PoiDraft;
use OCA\OpenRegister\Service\ShareLinkService;
use OCA\OpenRegister\Service\ShareDraft;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\Entity;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

if (class_exists('OCA\\Deck\\Service\\CardService') === false) {
	eval('namespace OCA\\Deck\\Service; class CardService {}');
}

if (class_exists('OCA\\Maps\\Service\\FavoritesService') === false) {
	eval('namespace OCA\\Maps\\Service; class FavoritesService {}');
}

/**
 * Golden pins for the link services' create/link methods.
 *
 * @covers \OCA\OpenRegister\Service\EmailLinkService
 * @covers \OCA\OpenRegister\Service\ShareLinkService
 * @covers \OCA\OpenRegister\Service\DeckLinkService
 * @covers \OCA\OpenRegister\Service\MapLinkService
 * @covers \OCA\OpenRegister\Service\LinkTarget
 * @covers \OCA\OpenRegister\Service\EmailMessageRef
 * @covers \OCA\OpenRegister\Service\ShareDraft
 * @covers \OCA\OpenRegister\Service\CardDraft
 * @covers \OCA\OpenRegister\Service\PoiDraft
 * @uses   \OCA\OpenRegister\Db\EmailLink
 * @uses   \OCA\OpenRegister\Db\DeckLink
 * @uses   \OCA\OpenRegister\Db\MapLink
 * @uses   \OCA\OpenRegister\AppInfo\ClassAliasMap
 * @uses   \OCA\OpenRegister\Service\MailTableQueries
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 */
class LinkServiceCreateGoldenTest extends TestCase {

	/**
	 * Email scenarios: message id, uid, schema id, message row (null = not found), user.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int, 3: array<string, mixed>|null, 4: string|null}>
	 */
	public static function emailScenarios(): array {
		$row = ['id' => 7, 'uid' => 'row-uid', 'subject' => 'Hello', 'sent_at' => 1767225600, 'sender_email' => 'a@b.nl'];
		return [
			'uid given' => ['7', 'uid-7', 4, $row, 'alice'],
			'uid from row' => ['7', '', 4, $row, 'alice'],
			'no schema' => ['7', 'uid-7', 0, $row, 'alice'],
			'row without uid or date' => ['7', '', 4, ['id' => 7, 'uid' => '', 'subject' => null], 'alice'],
			'message not found' => ['7', 'uid-7', 4, null, 'alice'],
			'bad message id' => ['x', '', 4, $row, 'alice'],
			'no user' => ['7', 'uid-7', 4, $row, null],
		];
	}//end emailScenarios()

	/**
	 * Email linking pins the inserted row.
	 *
	 * @param string                    $messageId Message id as sent.
	 * @param string                    $uid       Message uid as sent.
	 * @param int                       $schemaId  Schema id.
	 * @param array<string, mixed>|null $row       Mail message row.
	 * @param string|null               $uid2      Session user.
	 *
	 * @return void
	 *
	 * @dataProvider emailScenarios
	 */
	public function testLinkEmailGolden(string $messageId, string $uid, int $schemaId, ?array $row, ?string $uid2): void {
		$mapper = $this->getMockBuilder(EmailLinkMapper::class)->disableOriginalConstructor()
			->onlyMethods(['findByObjectAccountMessageUid', 'insert'])->getMock();
		$mapper->method('findByObjectAccountMessageUid')->willReturn(null);
		$mapper->method('insert')->willReturnArgument(0);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$db = $this->createMock(IDBConnection::class);
		// The first query is the account-owner lookup, the next the message.
		$answers = [['user_id' => $uid2]];
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$answers, $row) {
				return $this->rowBuilder(array_shift($answers) ?? $row);
			}
		);
		$service = new EmailLinkService($mapper, $db, new LinkServiceRuntime($apps, $this->session($uid2), $this->createMock(LoggerInterface::class)));

		$this->assertGolden(
			function () use ($service, $messageId, $uid, $schemaId) {
				return $this->entityFields($service->linkEmail((new LinkTarget('obj-1', 3))->inSchema($schemaId), (new EmailMessageRef(42, $messageId))->withUid($uid)));
			}
		);
	}//end testLinkEmailGolden()

	/**
	 * The linked mail's RFC `Message-ID` lands on the link, from the Mail row.
	 */
	public function testLinkEmailKeepsTheRfcMessageId(): void {
		$mapper = $this->getMockBuilder(EmailLinkMapper::class)->disableOriginalConstructor()
			->onlyMethods(['findByObjectAccountMessageUid', 'insert'])->getMock();
		$mapper->method('insert')->willReturnArgument(0);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			fn () => $this->rowBuilder(['id' => 7, 'uid' => 'u', 'subject' => 'Hello', 'message_id' => '<a1@x>', 'user_id' => 'alice'])
		);
		$service = new EmailLinkService($mapper, $db, new LinkServiceRuntime($apps, $this->session('alice'), $this->createMock(LoggerInterface::class)));

		$link = $service->linkEmail((new LinkTarget('obj-1', 3))->inSchema(4), (new EmailMessageRef(42, '7'))->withUid('u'));

		$this->assertSame('<a1@x>', $link->getRfcMessageId());
	}//end testLinkEmailKeepsTheRfcMessageId()

	/**
	 * Share scenarios: file id, type, recipient, permissions, password, expiration, manager failure.
	 *
	 * @return array<string, array{0: int, 1: int, 2: string|null, 3: int, 4: string|null, 5: string|null, 6: bool}>
	 */
	public static function shareScenarios(): array {
		return [
			'user share' => [10, IShare::TYPE_USER, ' bob ', 1, null, null, false],
			'link with password and expiry' => [10, IShare::TYPE_LINK, null, 17, 'secret', '2026-12-24', false],
			'email share, blank password' => [10, IShare::TYPE_EMAIL, 'x@y.nl', 1, ' ', ' ', false],
			'group without recipient' => [10, IShare::TYPE_GROUP, ' ', 1, null, null, false],
			'unsupported type' => [10, 99, 'bob', 1, null, null, false],
			'bad expiration' => [10, IShare::TYPE_LINK, null, 1, null, 'not a date', false],
			'file outside folder' => [11, IShare::TYPE_USER, 'bob', 1, null, null, false],
			'manager refuses' => [10, IShare::TYPE_USER, 'bob', 1, null, null, true],
		];
	}//end shareScenarios()

	/**
	 * Share creation pins the share setters and the error mapping.
	 *
	 * @param int         $fileId      File id.
	 * @param int         $shareType   Share type.
	 * @param string|null $shareWith   Recipient.
	 * @param int         $permissions Permissions.
	 * @param string|null $password    Password.
	 * @param string|null $expiration  Expiration date.
	 * @param bool        $fails       Whether the share manager throws.
	 *
	 * @return void
	 *
	 * @dataProvider shareScenarios
	 */
	public function testCreateShareGolden(int $fileId, int $shareType, ?string $shareWith, int $permissions, ?string $password, ?string $expiration, bool $fails): void {
		$calls = [];
		$share = $this->createMock(IShare::class);
		foreach (['setNode', 'setShareType', 'setSharedBy', 'setPermissions', 'setSharedWith', 'setPassword', 'setExpirationDate'] as $setter) {
			$share->method($setter)->willReturnCallback(
				function ($value) use (&$calls, $setter, $share) {
					$calls[] = [$setter, $this->scalar($value)];
					return $share;
				}
			);
		}

		$manager = $this->createMock(IManager::class);
		$manager->method('newShare')->willReturn($share);
		if ($fails === true) {
			$manager->method('createShare')->willThrowException(new RuntimeException('sharing disabled'));
		} else {
			$manager->method('createShare')->willReturnArgument(0);
		}

		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn(10);
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$node]);
		$handler = new class($folder, $this->session('openregister')->getUser()) {
			public function __construct(private Folder $folder, private \OCP\IUser $account) {
			}

			public function getObjectFolder(mixed $target): Folder {
				return $this->folder;
			}

			public function getUser(): \OCP\IUser {
				return $this->account;
			}
		};
		$objects = new class {
			public function find(string $uuid): \OCA\OpenRegister\Db\ObjectEntity {
				return new \OCA\OpenRegister\Db\ObjectEntity();
			}
		};
		$schemas = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
		$schemas->method('find')->willReturn(new \OCA\OpenRegister\Db\Schema());
		$rights = $this->createMock(\OCA\OpenRegister\Service\Object\PermissionHandlerInterface::class);
		$rights->method('hasPermission')->willReturn(true);
		$container = $this->container(
			[
				'OCP\\Share\\IManager' => $manager,
				'OCP\\IUserSession' => $this->session('alice'),
				\OCA\OpenRegister\Files\Service\File\FileRestrictionGuardInterface::class => new class {
					public function readableNodes(array $nodes): array {
						return $nodes;
					}
				},
				'OCA\\OpenRegister\\Service\\FileService' => $handler,
				'OCA\\OpenRegister\\Db\\MagicMapper' => $objects,
				'OCA\\OpenRegister\\Db\\SchemaMapper' => $schemas,
				'OCA\\OpenRegister\\Service\\Object\\PermissionHandlerInterface' => $rights,
			]
		);
		$l10n = $this->createMock(IL10N::class);
		$service = new ShareLinkService( $this->createMock(LoggerInterface::class), $container);

		$this->assertGolden(
			function () use ($service, &$calls, $fileId, $shareType, $shareWith, $permissions, $password, $expiration) {
				$result = $service->createShare(
					'obj-1',
					(new ShareDraft($fileId, $shareType))->withRecipient($shareWith, $permissions)->withProtection($password, $expiration)
				);
				return ['returnsBuiltShare' => $result instanceof IShare, 'calls' => $calls];
			}
		);
	}//end testCreateShareGolden()

	/**
	 * Deck scenarios: description, due date, card service failure.
	 *
	 * @return array<string, array{0: string|null, 1: string|null, 2: bool}>
	 */
	public static function deckScenarios(): array {
		return [
			'description and due date' => ['Follow up', '2026-12-01T10:00:00+00:00', false],
			'no description' => [null, null, false],
			'empty description, bad due date' => ['', 'soon', false],
			'card service fails' => ['x', null, true],
		];
	}//end deckScenarios()

	/**
	 * Card creation pins the Deck calls and the inserted link.
	 *
	 * @param string|null $description Card description.
	 * @param string|null $duedate     Due date.
	 * @param bool        $fails       Whether Deck throws.
	 *
	 * @return void
	 *
	 * @dataProvider deckScenarios
	 */
	public function testCreateAndLinkCardGolden(?string $description, ?string $duedate, bool $fails): void {
		$calls = [];
		$card = new class {
			public function getId(): int {
				return 77;
			}
		};
		$cards = new class($calls, $card, $fails) {
			public function __construct(private array &$calls, private object $card, private bool $fails) {
			}

			public function create(...$args): object {
				$this->calls[] = ['create', $args];
				if ($this->fails === true) {
					throw new RuntimeException('deck down');
				}

				return $this->card;
			}

			public function update(...$args): object {
				$this->calls[] = ['update', $args];
				return $this->card;
			}
		};
		$mapper = $this->getMockBuilder(DeckLinkMapper::class)->disableOriginalConstructor()->onlyMethods(['insert'])->getMock();
		$mapper->method('insert')->willReturnArgument(0);
		$service = new DeckLinkService(
			$mapper,
			new LinkServiceRuntime($this->createMock(IAppManager::class), $this->session('alice'), $this->createMock(LoggerInterface::class)),
			$this->createMock(IUserManager::class),
			$this->container(['OCA\\Deck\\Service\\CardService' => $cards])
		);

		$this->assertGolden(
			function () use ($service, &$calls, $description, $duedate) {
				try {
					$link = $service->createAndLinkCard(
						(new LinkTarget('obj-1', 3))->inSchema(4),
						(new CardDraft('Card', 5))->onBoard(12)->withDetails($description, $duedate)
					);
				} finally {
					$this->lastCalls = $calls;
				}

				return ['link' => $this->entityFields($link), 'calls' => $calls];
			}
		);
	}//end testCreateAndLinkCardGolden()

	/**
	 * Calls recorded before an exception, kept for the golden.
	 *
	 * @var array<int, mixed>
	 */
	private array $lastCalls = [];

	/**
	 * Map scenarios: name, category, comment, favourite id (0 = none, -1 = throws).
	 *
	 * @return array<string, array{0: string, 1: string|null, 2: string|null, 3: int}>
	 */
	public static function mapScenarios(): array {
		return [
			'full poi' => [' Office ', 'work', 'third floor', 31],
			'defaults' => ['Home', null, null, 32],
			'blank name' => ['  ', null, null, 31],
			'no favourite id' => ['Office', null, null, 0],
			'maps throws' => ['Office', null, null, -1],
		];
	}//end mapScenarios()

	/**
	 * POI creation pins the Maps call and the inserted link.
	 *
	 * @param string      $name       POI name.
	 * @param string|null $category   Category.
	 * @param string|null $comment    Comment.
	 * @param int         $favoriteId Favourite id returned by Maps.
	 *
	 * @return void
	 *
	 * @dataProvider mapScenarios
	 */
	public function testCreateAndLinkPoiGolden(string $name, ?string $category, ?string $comment, int $favoriteId): void {
		$calls = [];
		$favorites = new class($calls, $favoriteId) {
			public function __construct(private array &$calls, private int $favoriteId) {
			}

			public function addFavoriteToDB(...$args): int {
				$this->calls[] = $args;
				if ($this->favoriteId < 0) {
					throw new RuntimeException('maps down');
				}

				return $this->favoriteId;
			}
		};
		$mapper = $this->getMockBuilder(MapLinkMapper::class)->disableOriginalConstructor()->onlyMethods(['insert'])->getMock();
		$mapper->method('insert')->willReturnArgument(0);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$service = new MapLinkService(
			$mapper,
			$this->container(['OCA\\Maps\\Service\\FavoritesService' => $favorites]),
			$this->createMock(IURLGenerator::class),
			new LinkServiceRuntime(
				$apps,
				$this->session('alice'),
				$this->createMock(LoggerInterface::class)
			)
		);

		$this->assertGolden(
			function () use ($service, &$calls, $name, $category, $comment) {
				try {
					$link = $service->createAndLinkPoi(
						(new LinkTarget('obj-1', 3))->inSchema(4),
						(new PoiDraft($name))->locatedAt(52.37, 4.89)->withNotes($category, $comment)
					);
				} finally {
					$this->lastCalls = $calls;
				}

				return ['link' => $this->entityFields($link), 'calls' => $calls];
			}
		);
	}//end testCreateAndLinkPoiGolden()

	/**
	 * Run the action and compare its result (or exception) with the recorded golden.
	 *
	 * @param callable $action Produces the scenario's observable result.
	 *
	 * @return void
	 */
	private function assertGolden(callable $action): void {
		$this->lastCalls = [];
		try {
			$actual = ['result' => $action()];
		} catch (Throwable $e) {
			$actual = ['error' => [get_class($e), $e->getMessage(), $e->getCode()], 'calls' => $this->lastCalls];
		}

		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE), true);
		$file = __DIR__ . '/fixtures/link-service-create-golden.json';
		$name = static::class . '::' . $this->name() . ' ' . $this->dataName();
		$name = substr($name, strrpos($name, '\\') + 1);
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
	 * The entity's fields, with the link time reduced to its type.
	 *
	 * @param Entity $entity The link entity.
	 *
	 * @return array<string, mixed>
	 */
	private function entityFields(Entity $entity): array {
		$fields = [];
		foreach (array_keys($entity->getFieldTypes()) as $field) {
			$fields[$field] = $this->scalar($entity->{'get' . ucfirst($field)}());
		}

		$fields['linkedAt'] = get_debug_type($fields['linkedAt'] ?? null);
		return $fields;
	}//end entityFields()

	/**
	 * A JSON-friendly form of a recorded value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function scalar(mixed $value): mixed {
		if ($value instanceof DateTimeInterface) {
			return $value->format('c');
		}

		if ($value instanceof Node) {
			return 'node#' . $value->getId();
		}

		return $value;
	}//end scalar()

	/**
	 * A user session for the given uid, or none.
	 *
	 * @param string|null $uid The uid.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
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

	/**
	 * A query builder whose query returns the given row.
	 *
	 * @param array<string, mixed>|null $row The row, or null for none.
	 *
	 * @return IQueryBuilder
	 */
	private function rowBuilder(?array $row): IQueryBuilder {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($row ?? false);
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'addSelect', 'from', 'innerJoin', 'leftJoin', 'where', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}//end rowBuilder()
}//end class
