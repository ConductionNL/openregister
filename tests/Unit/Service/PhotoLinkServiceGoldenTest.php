<?php

/**
 * Golden tests pinning {@see \OCA\OpenRegister\Service\PhotoLinkService} paths the
 * contract test leaves open: album lookups that fail or come back partial, the stale
 * refresh, the album picker and the create failure.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\PhotoLink;
use OCA\OpenRegister\Db\PhotoLinkMapper;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\PhotoLinkService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * PhotoLinkServiceGoldenTest.
 *
 * @covers \OCA\OpenRegister\Service\PhotoLinkService
 * @uses   \OCA\OpenRegister\Service\LinkTarget
 */
class PhotoLinkServiceGoldenTest extends TestCase {

	private PhotoLinkMapper&MockObject $mapper;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->mapper = $this->getMockBuilder(PhotoLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid', 'findByObjectAndAlbum', 'deleteByObjectAndAlbum', 'insert', 'update'])
			->getMock();
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A Photos album double.
	 *
	 * @param mixed $lastAdded Last-added timestamp, or a Throwable to throw.
	 *
	 * @return object
	 */
	private function album(int $id, string $title, mixed $lastAdded = 0): object {
		return new class($id, $title, $lastAdded) {
			public function __construct(private int $id, private string $title, private mixed $lastAdded) {
			}

			public function getId(): int {
				return $this->id;
			}

			public function getTitle(): string {
				return $this->title;
			}

			public function getLastAddedPhoto(): int {
				if ($this->lastAdded instanceof \Throwable) {
					throw $this->lastAdded;
				}

				return $this->lastAdded;
			}
		};
	}//end album()

	/**
	 * A Photos AlbumMapper double; each behaviour is a value or a Throwable to throw.
	 *
	 * @param array<string,mixed> $behaviour get / getForUser / files / create.
	 *
	 * @return object
	 */
	private function albums(array $behaviour): object {
		return new class($behaviour) {
			public array $calls = [];

			public function __construct(private array $behaviour) {
			}

			private function answer(string $key): mixed {
				$value = $this->behaviour[$key] ?? null;
				if ($value instanceof \Throwable) {
					throw $value;
				}

				return $value;
			}

			public function get(int $id): ?object {
				$this->calls[] = 'get:' . $id;
				return $this->answer('get');
			}

			public function getForUser(string $uid): array {
				$this->calls[] = 'getForUser:' . $uid;
				return $this->answer('getForUser');
			}

			public function getForAlbumIdAndUserWithFiles(int $id, string $uid, array $filters): array {
				$this->calls[] = 'files:' . $id . ':' . $uid;
				return $this->answer('files');
			}

			public function create(string $uid, string $name): object {
				$this->calls[] = 'create:' . $uid . ':' . $name;
				return $this->answer('create');
			}
		};
	}//end albums()

	private function service(?object $albums, ?string $uid = 'alice', bool $enabled = true): PhotoLinkService {
		$container = $this->createMock(ContainerInterface::class);
		if ($albums === null) {
			$container->method('get')->willThrowException(new RuntimeException('no photos'));
		} else {
			$container->method('get')->willReturn($albums);
		}

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($enabled);
		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/apps/photos/');

		return new PhotoLinkService($this->mapper, $container, $apps, $session, $urls, $this->logger);
	}//end service()

	private function target(): LinkTarget {
		return (new LinkTarget('obj-1', 3))->inSchema(4);
	}//end target()

	public function testLinkingCachesTheAlbumInfo(): void {
		$albums = $this->albums(['get' => $this->album(9, 'Sea & Sun', 1767225600), 'files' => [1, 2]]);

		$link = $this->service($albums)->linkAlbum($this->target(), 9);

		$this->assertSame(9, $link->getAlbumId());
		$this->assertSame('Sea & Sun', $link->getAlbumName());
		$this->assertSame('/apps/photos/albums/Sea%20%26%20Sun', $link->getCoverPhotoUrl());
		$this->assertSame(2, $link->getPhotoCount());
		$this->assertSame(1767225600, $link->getLastEdited()->getTimestamp());
		$this->assertSame(['get:9', 'files:9:alice'], $albums->calls);
	}//end testLinkingCachesTheAlbumInfo()

	public function testAnAlbumWithoutPhotosOrCountStillLinks(): void {
		$albums = $this->albums(['get' => $this->album(9, 'A', new RuntimeException('no ts')), 'files' => new RuntimeException('no files')]);
		$this->logger->expects($this->once())->method('debug')->with('PhotoLinkService::countPhotos failed: no files');

		$link = $this->service($albums)->linkAlbum($this->target(), 9);

		$this->assertNull($link->getPhotoCount());
		$this->assertNull($link->getLastEdited());
	}//end testAnAlbumWithoutPhotosOrCountStillLinks()

	public function testAZeroLastAddedPhotoLeavesNoLastEdited(): void {
		$albums = $this->albums(['get' => $this->album(9, 'A', 0), 'files' => []]);

		$link = $this->service($albums)->linkAlbum($this->target(), 9);

		$this->assertNull($link->getLastEdited());
		$this->assertSame(0, $link->getPhotoCount());
	}//end testAZeroLastAddedPhotoLeavesNoLastEdited()

	public function testAMissingAlbumIs404(): void {
		$this->expectExceptionMessage('Photos album not found');
		$this->expectExceptionCode(404);
		$this->service($this->albums(['get' => null]))->linkAlbum($this->target(), 9);
	}//end testAMissingAlbumIs404()

	public function testAFailingAlbumLookupIs404AndLogged(): void {
		$this->logger->expects($this->once())->method('debug')->with('PhotoLinkService::fetchAlbumInfo get failed: gone');
		$this->expectExceptionCode(404);
		$this->service($this->albums(['get' => new RuntimeException('gone')]))->linkAlbum($this->target(), 9);
	}//end testAFailingAlbumLookupIs404AndLogged()

	public function testAnUnresolvableAlbumMapperIs404OnLink(): void {
		$this->logger->expects($this->once())->method('debug')->with('PhotoLinkService: AlbumMapper unavailable: no photos');
		$this->expectExceptionCode(404);
		$this->service(null)->linkAlbum($this->target(), 9);
	}//end testAnUnresolvableAlbumMapperIs404OnLink()

	public function testAnUnresolvableAlbumMapperIs503OnCreate(): void {
		$this->expectExceptionMessage('NC Photos is not available');
		$this->expectExceptionCode(503);
		$this->service(null)->createAndLinkAlbum($this->target(), 'x');
	}//end testAnUnresolvableAlbumMapperIs503OnCreate()

	public function testAFailingCreateIsLoggedAndBecomesA500(): void {
		$this->logger->expects($this->once())->method('warning')->with('PhotoLinkService::createAndLinkAlbum failed: nope');
		try {
			$this->service($this->albums(['create' => new RuntimeException('nope')]))->createAndLinkAlbum($this->target(), 'x');
			$this->fail('expected an exception');
		} catch (Exception $e) {
			$this->assertSame('Failed to create Photos album', $e->getMessage());
			$this->assertSame(500, $e->getCode());
		}
	}//end testAFailingCreateIsLoggedAndBecomesA500()

	public function testCreateStoresTheTrimmedNameAndAnEmptyAlbum(): void {
		$albums = $this->albums(['create' => $this->album(12, 'ignored')]);

		$link = $this->service($albums)->createAndLinkAlbum($this->target(), ' Trip ');

		$this->assertSame(12, $link->getAlbumId());
		$this->assertSame('Trip', $link->getAlbumName());
		$this->assertNull($link->getCoverPhotoUrl());
		$this->assertSame(0, $link->getPhotoCount());
		$this->assertNotNull($link->getLastEdited());
		$this->assertSame(['create:alice:Trip'], $albums->calls);
	}//end testCreateStoresTheTrimmedNameAndAnEmptyAlbum()

	private function storedLink(?DateTime $linkedAt): PhotoLink {
		$link = new PhotoLink();
		$link->setObjectUuid('obj-1');
		$link->setAlbumId(9);
		$link->setAlbumName('Old name');
		$link->setPhotoCount(1);
		$link->setLinkedAt($linkedAt);
		return $link;
	}//end storedLink()

	public function testAStaleLinkIsRefreshedAndSaved(): void {
		$link = $this->storedLink(linkedAt: null);
		$this->mapper->method('findByObjectUuid')->willReturn([$link]);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$rows = $this->service($this->albums(['get' => $this->album(9, 'New name', 0), 'files' => [1, 2, 3]]))->getLinkedAlbums('obj-1');

		$this->assertSame('New name', $rows[0]['albumName']);
		$this->assertSame(3, $rows[0]['photoCount']);
		$this->assertSame('/apps/photos/albums/New%20name', $rows[0]['url']);
		$this->assertNotNull($link->getLinkedAt());
	}//end testAStaleLinkIsRefreshedAndSaved()

	public function testAFailedRefreshSaveReturnsTheUpdatedLink(): void {
		$this->mapper->method('findByObjectUuid')->willReturn([$this->storedLink(linkedAt: null)]);
		$this->mapper->method('update')->willThrowException(new RuntimeException('db'));
		$this->logger->expects($this->once())->method('debug')->with('PhotoLinkService::refreshLink update failed: db');

		$rows = $this->service($this->albums(['get' => $this->album(9, 'New name', 0), 'files' => []]))->getLinkedAlbums('obj-1');

		$this->assertSame('New name', $rows[0]['albumName']);
	}//end testAFailedRefreshSaveReturnsTheUpdatedLink()

	public function testAnUnfoundStaleAlbumKeepsTheCachedRow(): void {
		$this->mapper->method('findByObjectUuid')->willReturn([$this->storedLink(linkedAt: null)]);
		$this->mapper->expects($this->never())->method('update');

		$rows = $this->service($this->albums(['get' => null]))->getLinkedAlbums('obj-1');

		$this->assertSame('Old name', $rows[0]['albumName']);
		$this->assertSame('/apps/photos/albums/Old%20name', $rows[0]['url']);
	}//end testAnUnfoundStaleAlbumKeepsTheCachedRow()

	public function testNoRefreshWithoutAUserOrWhenFresh(): void {
		$this->mapper->method('findByObjectUuid')->willReturn([$this->storedLink(linkedAt: null), $this->storedLink(linkedAt: new DateTime())]);
		$albums = $this->albums(['get' => $this->album(9, 'New name', 0), 'files' => []]);

		$rows = $this->service($albums, uid: null)->getLinkedAlbums('obj-1');

		$this->assertSame('Old name', $rows[0]['albumName']);
		$this->assertSame([], $albums->calls);
		$this->assertCount(2, $rows);
	}//end testNoRefreshWithoutAUserOrWhenFresh()

	public function testThePickerFiltersCaseInsensitivelyAndSkipsBrokenAlbums(): void {
		$broken = new class {
			public function getTitle(): string {
				throw new RuntimeException('broken');
			}
		};
		$albums = $this->albums(['getForUser' => [$this->album(1, 'Beach Day'), $broken, $this->album(2, 'Office')]]);

		$rows = $this->service($albums)->getAvailableAlbums('BEACH');

		$this->assertSame(
			[['id' => 1, 'name' => 'Beach Day', 'coverPhotoUrl' => '/apps/photos/albums/Beach%20Day', 'photoCount' => null, 'url' => '/apps/photos/albums/Beach%20Day']],
			$rows
		);
	}//end testThePickerFiltersCaseInsensitivelyAndSkipsBrokenAlbums()

	public function testAnEmptySearchListsEveryAlbum(): void {
		$rows = $this->service($this->albums(['getForUser' => [$this->album(1, 'A'), $this->album(2, 'B')]]))->getAvailableAlbums('');

		$this->assertSame([1, 2], array_column($rows, 'id'));
	}//end testAnEmptySearchListsEveryAlbum()

	public function testAFailingPickerLookupIsLoggedAndEmpty(): void {
		$this->logger->expects($this->once())->method('warning')->with('PhotoLinkService::getAvailableAlbums failed: down');

		$this->assertSame([], $this->service($this->albums(['getForUser' => new RuntimeException('down')]))->getAvailableAlbums(null));
	}//end testAFailingPickerLookupIsLoggedAndEmpty()
}//end class
