<?php

/**
 * Unit tests for PhotoLinkService — the paths that reach the Photos album mapper.
 *
 * The Photos AlbumMapper is resolved from the container by its class name, so
 * the container here hands out a fake whose methods answer from a map. Each
 * test decides one branch: Photos off, the mapper unresolvable, an album that
 * is gone, a failing photo count, a stale link refreshed (or its update
 * failing), a search needle, an album whose getters throw.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses -- the test double lives next to the test.

use DateTime;
use Exception;
use OCA\OpenRegister\Db\PhotoLink;
use OCA\OpenRegister\Db\PhotoLinkMapperInterface;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\PhotoLinkService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\Entity;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * An object answering method calls from a map: a Throwable is thrown, a Closure is called, a missing method throws.
 */
class PhotoLinkServiceAlbumTestFake {

	/**
	 * @param array<string, mixed> $answers Method => answer.
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

final class PhotoLinkServiceAlbumTest extends TestCase {

	private const ALBUM_MAPPER = 'OCA\\Photos\\Album\\AlbumMapper';

	private bool $photosEnabled = true;

	private ?string $uid = 'alice';

	/** @var mixed The album mapper the container hands out, or a Throwable to throw. */
	private mixed $albumMapper = null;

	/** @var string[] */
	private array $logs = [];

	private PhotoLinkMapperInterface&MockObject $links;

	private function service(): PhotoLinkService {
		$this->links = $this->createMock(PhotoLinkMapperInterface::class);
		$this->links->method('insert')->willReturnCallback(static fn (Entity $e): Entity => $e);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) {
			$this->assertSame(self::ALBUM_MAPPER, $id);
			if ($this->albumMapper instanceof Throwable) {
				throw $this->albumMapper;
			}
			return $this->albumMapper;
		});
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(static fn (string $route) => '/r/' . $route . '/');

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app) => $app === 'photos' && $this->photosEnabled);
		$session = $this->createMock(IUserSession::class);
		if ($this->uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->uid);
			$session->method('getUser')->willReturn($user);
		}
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message) use ($level): void {
				$this->logs[] = $level . ': ' . $message;
			});
		}

		return new PhotoLinkService($this->links, $container, $urls, new LinkServiceRuntime($apps, $session, $logger));
	}

	private function album(array $answers): PhotoLinkServiceAlbumTestFake {
		return new PhotoLinkServiceAlbumTestFake($answers);
	}

	private function mapper(array $albums, mixed $files = []): PhotoLinkServiceAlbumTestFake {
		return $this->album([
			'get' => function (int $id) use ($albums) {
				return $albums[$id] ?? throw new RuntimeException('album ' . $id . ' not found');
			},
			'getForAlbumIdAndUserWithFiles' => function (int $id, string $uid, array $x) use ($files) {
				if ($files instanceof Throwable) {
					throw $files;
				}
				return $files;
			},
			'getForUser' => fn (string $uid) => array_values($albums),
			'create' => fn (string $uid, string $name) => $this->album(['getId' => '77']),
		]);
	}

	private function target(): LinkTarget {
		return (new LinkTarget('obj-1', 3))->inSchema(4);
	}

	private function stored(int $albumId, string $name, ?DateTime $linkedAt): PhotoLink {
		$link = new PhotoLink();
		$link->setObjectUuid('obj-1');
		$link->setAlbumId($albumId);
		$link->setAlbumName($name);
		$link->setPhotoCount(1);
		$link->setLinkedAt($linkedAt);
		return $link;
	}

	public function testLinkAlbumStoresTheAlbumsNameCountCoverAndLastAddedPhoto(): void {
		$this->albumMapper = $this->mapper([5 => $this->album(['getTitle' => 'Zomer 2026', 'getLastAddedPhoto' => '1700000000'])], ['f1', 'f2', 'f3']);
		$service = $this->service();
		$this->links->method('findByObjectAndAlbum')->with('obj-1', 5)->willReturn(null);

		$link = $service->linkAlbum($this->target(), 5);

		$this->assertSame(
			[5, 'Zomer 2026', '/r/photos.page.index/albums/Zomer%202026', 3, 1700000000, 'alice', 'obj-1', 4],
			[$link->getAlbumId(), $link->getAlbumName(), $link->getCoverPhotoUrl(), $link->getPhotoCount(), $link->getLastEdited()->getTimestamp(), $link->getLinkedBy(), $link->getObjectUuid(), $link->getSchemaId()]
		);
	}

	public function testNoLastAddedPhotoOrAThrowingGetterLeavesLastEditedEmptyAndAFailingCountIsNull(): void {
		$this->albumMapper = $this->mapper(
			[5 => $this->album(['getTitle' => 'A', 'getLastAddedPhoto' => 0]), 6 => $this->album(['getTitle' => 'B', 'getLastAddedPhoto' => new RuntimeException('old photos')])],
			new RuntimeException('no files')
		);
		$service = $this->service();
		$this->links->method('findByObjectAndAlbum')->willReturn(null);

		$a = $service->linkAlbum($this->target(), 5);
		$b = $service->linkAlbum($this->target(), 6);

		$this->assertNull($a->getLastEdited());
		$this->assertNull($b->getLastEdited());
		$this->assertNull($a->getPhotoCount());
		$this->assertSame(['debug: PhotoLinkService::countPhotos failed: no files', 'debug: PhotoLinkService::countPhotos failed: no files'], $this->logs);
	}

	public function testLinkingAnAlbumThatIsGoneIs404AndLogged(): void {
		$this->albumMapper = $this->mapper([]);
		$service = $this->service();
		$this->links->method('findByObjectAndAlbum')->willReturn(null);
		$this->links->expects($this->never())->method('insert');

		try {
			$service->linkAlbum($this->target(), 9);
			$this->fail('A missing album must be refused.');
		} catch (Exception $e) {
			$this->assertSame([404, 'Photos album not found'], [$e->getCode(), $e->getMessage()]);
		}
		$this->assertSame(['debug: PhotoLinkService::fetchAlbumInfo get failed: album 9 not found'], $this->logs);
	}

	public function testAnUnresolvableAlbumMapperReadsAsNoAlbum(): void {
		$this->albumMapper = new RuntimeException('photos not booted');
		$service = $this->service();
		$this->links->method('findByObjectAndAlbum')->willReturn(null);

		try {
			$service->linkAlbum($this->target(), 5);
			$this->fail('Without a mapper no album is found.');
		} catch (Exception $e) {
			$this->assertSame(404, $e->getCode());
		}
		$this->assertSame(['debug: PhotoLinkService: AlbumMapper unavailable: photos not booted'], $this->logs);
	}

	public function testLinkAlbumRefusesWithoutPhotosAndOnADuplicate(): void {
		$this->photosEnabled = false;
		$service = $this->service();
		try {
			$service->linkAlbum($this->target(), 5);
			$this->fail('Photos off must be refused.');
		} catch (Exception $e) {
			$this->assertSame(503, $e->getCode());
		}

		$this->photosEnabled = true;
		$service = $this->service();
		$this->links->method('findByObjectAndAlbum')->willReturn($this->stored(5, 'x', null));
		try {
			$service->linkAlbum($this->target(), 5);
			$this->fail('A duplicate must be refused.');
		} catch (Exception $e) {
			$this->assertSame(409, $e->getCode());
		}
	}

	public function testCreateAndLinkAlbumCreatesItUnderTheTrimmedNameAndLinksIt(): void {
		$created = [];
		$this->albumMapper = $this->album(['create' => function (string $uid, string $name) use (&$created) {
			$created[] = [$uid, $name];
			return $this->album(['getId' => '77']);
		}]);
		$service = $this->service();

		$link = $service->createAndLinkAlbum($this->target(), '  Uitje  ');

		$this->assertSame([['alice', 'Uitje']], $created);
		$this->assertSame([77, 'Uitje', null, 0, 'alice'], [$link->getAlbumId(), $link->getAlbumName(), $link->getCoverPhotoUrl(), $link->getPhotoCount(), $link->getLinkedBy()]);
	}

	public function testCreateAndLinkAlbumRefusesABlankNameAndPhotosOff(): void {
		$service = $this->service();
		try {
			$service->createAndLinkAlbum($this->target(), '   ');
			$this->fail('A blank name must be refused.');
		} catch (Exception $e) {
			$this->assertSame(400, $e->getCode());
		}

		$this->photosEnabled = false;
		$service = $this->service();
		try {
			$service->createAndLinkAlbum($this->target(), 'X');
			$this->fail('Photos off must be refused.');
		} catch (Exception $e) {
			$this->assertSame(503, $e->getCode());
		}
	}

	public function testAFailingAlbumCreateIsLoggedAnd500(): void {
		$this->albumMapper = $this->album(['create' => new RuntimeException('quota')]);
		$service = $this->service();
		$this->links->expects($this->never())->method('insert');

		try {
			$service->createAndLinkAlbum($this->target(), 'X');
			$this->fail('A failing create must surface.');
		} catch (Exception $e) {
			$this->assertSame([500, 'Failed to create Photos album'], [$e->getCode(), $e->getMessage()]);
		}
		$this->assertSame(['warning: PhotoLinkService::createAndLinkAlbum failed: quota'], $this->logs);
	}

	public function testUnlinkRefusesWithoutAUserAndWhenNothingWasDeleted(): void {
		$service = $this->service();
		$this->links->method('deleteByObjectAndAlbum')->willReturnOnConsecutiveCalls(1, 0);
		$service->unlinkAlbum('obj-1', 5);
		try {
			$service->unlinkAlbum('obj-1', 6);
			$this->fail('Nothing deleted must be 404.');
		} catch (Exception $e) {
			$this->assertSame(404, $e->getCode());
		}

		$this->uid = null;
		$service = $this->service();
		$this->links->expects($this->never())->method('deleteByObjectAndAlbum');
		try {
			$service->unlinkAlbum('obj-1', 5);
			$this->fail('No user must be 401.');
		} catch (Exception $e) {
			$this->assertSame([401, 'No user logged in'], [$e->getCode(), $e->getMessage()]);
		}
	}

	public function testStaleLinksAreRefreshedAndFreshOrGoneOnesAreKept(): void {
		$this->albumMapper = $this->mapper([5 => $this->album(['getTitle' => 'Nieuw', 'getLastAddedPhoto' => 0])], ['a', 'b']);
		$service = $this->service();
		$stale = $this->stored(5, 'Oud', new DateTime('@' . (time() - 90000)));
		$never = $this->stored(9, 'Weg', null);
		$fresh = $this->stored(5, 'Vers', new DateTime());
		$this->links->method('findByObjectUuid')->willReturn([$stale, $never, $fresh]);
		$this->links->expects($this->once())->method('update')->with($this->identicalTo($stale))->willReturnArgument(0);

		$rows = $service->getLinkedAlbums('obj-1');

		$this->assertSame(['Nieuw', 'Weg', 'Vers'], array_column($rows, 'albumName'));
		$this->assertSame(2, $rows[0]['photoCount']);
		$this->assertSame('/r/photos.page.index/albums/Nieuw', $rows[0]['url']);
		$this->assertGreaterThan(time() - 60, $stale->getLinkedAt()->getTimestamp(), 'A refreshed link is stamped now.');
	}

	public function testAFailingRefreshUpdateKeepsTheRefreshedLink(): void {
		$this->albumMapper = $this->mapper([5 => $this->album(['getTitle' => 'Nieuw', 'getLastAddedPhoto' => 0])]);
		$service = $this->service();
		$this->links->method('findByObjectUuid')->willReturn([$this->stored(5, 'Oud', null)]);
		$this->links->method('update')->willThrowException(new RuntimeException('locked'));

		$rows = $service->getLinkedAlbums('obj-1');

		$this->assertSame('Nieuw', $rows[0]['albumName']);
		$this->assertSame(['debug: PhotoLinkService::refreshLink update failed: locked'], $this->logs);
	}

	public function testWithoutPhotosOrAUserLinkedAlbumsAreServedFromTheCache(): void {
		$this->photosEnabled = false;
		$service = $this->service();
		$this->links->method('findByObjectUuid')->willReturn([$this->stored(5, 'Oud', null)]);
		$this->links->expects($this->never())->method('update');
		$this->assertSame('Oud', $service->getLinkedAlbums('obj-1')[0]['albumName']);

		$this->photosEnabled = true;
		$this->uid = null;
		$this->albumMapper = $this->mapper([5 => $this->album(['getTitle' => 'Nieuw'])]);
		$service = $this->service();
		$this->links->method('findByObjectUuid')->willReturn([$this->stored(5, 'Oud', null)]);
		$this->links->expects($this->never())->method('update');
		$this->assertSame('Oud', $service->getLinkedAlbums('obj-1')[0]['albumName']);
	}

	public function testAvailableAlbumsAreFilteredCaseInsensitivelyAndSkipUnreadableAlbums(): void {
		$this->albumMapper = $this->mapper([
			1 => $this->album(['getTitle' => 'Zomer', 'getId' => '1']),
			2 => $this->album(['getTitle' => 'Winter', 'getId' => 2]),
			3 => $this->album(['getTitle' => new RuntimeException('broken')]),
		]);
		$service = $this->service();

		$this->assertSame(
			[['id' => 1, 'name' => 'Zomer', 'coverPhotoUrl' => '/r/photos.page.index/albums/Zomer', 'photoCount' => null, 'url' => '/r/photos.page.index/albums/Zomer']],
			$service->getAvailableAlbums('ZOM')
		);
		$this->assertSame(['Zomer', 'Winter'], array_column($service->getAvailableAlbums(''), 'name'));
		$this->assertSame(['Zomer', 'Winter'], array_column($service->getAvailableAlbums(null), 'name'));
	}

	public function testAvailableAlbumsAreEmptyWithoutAMapperOrAUserAndWhenListingFails(): void {
		$this->photosEnabled = false;
		$this->assertSame([], $this->service()->getAvailableAlbums());

		$this->photosEnabled = true;
		$this->uid = null;
		$this->albumMapper = $this->mapper([1 => $this->album(['getTitle' => 'Z', 'getId' => 1])]);
		$this->assertSame([], $this->service()->getAvailableAlbums());

		$this->uid = 'alice';
		$this->albumMapper = $this->album(['getForUser' => new RuntimeException('db')]);
		$this->assertSame([], $this->service()->getAvailableAlbums());
		$this->assertSame(['warning: PhotoLinkService::getAvailableAlbums failed: db'], $this->logs);
	}
}
