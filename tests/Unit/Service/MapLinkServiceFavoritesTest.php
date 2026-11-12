<?php

/**
 * MapLinkService against a present Maps app: linking, creating and listing POIs.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\MapLink;
use OCA\OpenRegister\Db\MapLinkMapperInterface;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\MapLinkService;
use OCA\OpenRegister\Service\PoiDraft;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

if (class_exists('OCA\\Maps\\Service\\FavoritesService') === false) {
	// The Maps app is not installed in the test run; its service class only has to exist.
	eval('namespace OCA\\Maps\\Service; class FavoritesService {}');
}

/**
 * The branches that need the Maps FavoritesService to be resolvable.
 *
 * @covers \OCA\OpenRegister\Service\MapLinkService
 * @uses \OCA\OpenRegister\Service\LinkServiceRuntime
 * @uses \OCA\OpenRegister\Service\LinkTarget
 * @uses \OCA\OpenRegister\Service\PoiDraft
 * @uses \OCA\OpenRegister\Db\MapLink
 * @uses \OCA\OpenRegister\Db\TypedFieldsJson
 */
class MapLinkServiceFavoritesTest extends TestCase {

	/**
	 * The link mapper.
	 *
	 * @var MapLinkMapperInterface&MockObject
	 */
	private MapLinkMapperInterface $mapper;

	/**
	 * The container the Maps service is resolved from.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager $appManager;

	/**
	 * The user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * A stand-in for the Maps FavoritesService, recording its calls.
	 *
	 * @var object
	 */
	private object $favorites;

	/**
	 * Build the boundaries: Maps enabled, alice logged in, the fake favorites service resolvable.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(MapLinkMapperInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appManager->method('isEnabledForUser')->with('maps')->willReturn(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->favorites = new class {
			/**
			 * Calls made, by method.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * What the next call answers, or a throwable to throw.
			 *
			 * @var mixed
			 */
			public mixed $answer = null;

			/**
			 * Answer or throw.
			 *
			 * @param array<int, mixed> $call The call.
			 *
			 * @return mixed
			 */
			private function respond(array $call): mixed {
				$this->calls[] = $call;
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}

			/**
			 * Maps' addFavoriteToDB.
			 *
			 * @return mixed
			 */
			public function addFavoriteToDB($uid, $name, $lat, $lng, $category, $comment, $extensions): mixed {
				return $this->respond(call: ['add', $uid, $name, $lat, $lng, $category, $comment, $extensions]);
			}

			/**
			 * Maps' getFavoritesFromDB.
			 *
			 * @return mixed
			 */
			public function getFavoritesFromDB($uid): mixed {
				return $this->respond(call: ['list', $uid]);
			}

			/**
			 * Maps' getFavoriteFromDB.
			 *
			 * @return mixed
			 */
			public function getFavoriteFromDB($id, $uid): mixed {
				return $this->respond(call: ['get', $id, $uid]);
			}
		};
		$this->container->method('get')->with('OCA\\Maps\\Service\\FavoritesService')->willReturn($this->favorites);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return MapLinkService
	 */
	private function service(): MapLinkService {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->with('maps.page.index')->willReturn('/apps/maps/');

		return new MapLinkService($this->mapper, $this->container, $urls, new LinkServiceRuntime($this->appManager, $this->userSession, $this->logger));
	}//end service()

	/**
	 * Linking an existing POI copies its fields from Maps onto the stamped link.
	 *
	 * @return void
	 */
	public function testLinkingCopiesThePoiFromMaps(): void {
		$this->favorites->answer = ['name' => 'Town hall', 'category' => 'civic', 'lat' => '52.1', 'lng' => 4.3];
		$this->mapper->method('findByObjectAndFavorite')->with('obj-1', 12)->willReturn(null);
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$link = $this->service()->linkPoi(new LinkTarget(objectUuid: 'obj-1', registerId: 3), 12);

		$this->assertSame([['get', 12, 'alice']], $this->favorites->calls);
		$this->assertSame(12, $link->getFavoriteId());
		$this->assertSame('Town hall', $link->getName());
		$this->assertSame('civic', $link->getCategory());
		$this->assertSame(52.1, $link->getLat());
		$this->assertSame(4.3, $link->getLng());
		$this->assertNull($link->getComment());
		$this->assertSame('obj-1', $link->getObjectUuid());
		$this->assertSame('alice', $link->getLinkedBy());
	}//end testLinkingCopiesThePoiFromMaps()

	/**
	 * A POI Maps does not answer with an array, or whose read throws, is not found and nothing is inserted.
	 *
	 * @return void
	 */
	public function testAPoiMapsCannotReturnIsNotFound(): void {
		$this->mapper->method('findByObjectAndFavorite')->willReturn(null);
		$this->mapper->expects($this->never())->method('insert');
		$this->logger->expects($this->once())->method('debug')->with('MapLinkService::fetchFavoriteInfo failed: gone');
		$service = $this->service();
		$codes = [];
		foreach ([false, new RuntimeException('gone')] as $answer) {
			$this->favorites->answer = $answer;
			try {
				$service->linkPoi(new LinkTarget(objectUuid: 'obj-1', registerId: 3), 12);
			} catch (Exception $e) {
				$codes[] = [$e->getCode(), $e->getMessage()];
			}
		}

		$this->assertSame([[404, 'Maps POI not found'], [404, 'Maps POI not found']], $codes);
	}//end testAPoiMapsCannotReturnIsNotFound()

	/**
	 * Creating a POI trims the name, adds it to Maps and links the new id with the draft's fields.
	 *
	 * @return void
	 */
	public function testCreatingAddsToMapsAndLinksTheNewId(): void {
		$this->favorites->answer = '77';
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$poi = (new PoiDraft(name: '  Depot  '))->locatedAt(lat: 51.5, lng: 5.5)->withNotes(category: 'work', comment: 'gate B');

		$link = $this->service()->createAndLinkPoi(new LinkTarget(objectUuid: 'obj-2', registerId: 3), $poi);

		$this->assertSame([['add', 'alice', 'Depot', 51.5, 5.5, 'work', 'gate B', null]], $this->favorites->calls);
		$this->assertSame(77, $link->getFavoriteId());
		$this->assertSame('Depot', $link->getName());
		$this->assertSame('gate B', $link->getComment());
	}//end testCreatingAddsToMapsAndLinksTheNewId()

	/**
	 * A blank name is refused before Maps is called; a Maps failure or a non-positive id is a 500 and nothing is linked.
	 *
	 * @return void
	 */
	public function testCreatingRefusesABlankNameAndAMapsFailure(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->logger->expects($this->once())->method('warning')->with('MapLinkService::createAndLinkPoi failed: maps down');
		$service = $this->service();
		$target = new LinkTarget(objectUuid: 'obj-2', registerId: 3);
		$outcomes = [];
		foreach ([['   ', null], ['Depot', new RuntimeException('maps down')], ['Depot', 0]] as [$name, $answer]) {
			$this->favorites->answer = $answer;
			try {
				$service->createAndLinkPoi($target, new PoiDraft(name: $name));
			} catch (Exception $e) {
				$outcomes[] = [$e->getCode(), $e->getMessage()];
			}
		}

		$this->assertSame([[400, 'POI name is required'], [500, 'Failed to create Maps POI'], [500, 'Failed to create Maps POI']], $outcomes);
		$this->assertCount(2, $this->favorites->calls, 'the blank name never reached Maps');
	}//end testCreatingRefusesABlankNameAndAMapsFailure()

	/**
	 * The picker lists the user's favourites with a valid id whose name matches, case-insensitively, with a deep link.
	 *
	 * @return void
	 */
	public function testThePickerFiltersByIdAndName(): void {
		$this->favorites->answer = [
			['id' => 1, 'name' => 'Town Hall', 'category' => 'civic', 'lat' => 52, 'lng' => 4],
			['id' => 0, 'name' => 'Town square'],
			['id' => 2, 'name' => 'Harbour'],
		];
		$service = $this->service();

		$this->assertSame(
			[['id' => 1, 'name' => 'Town Hall', 'category' => 'civic', 'lat' => 52.0, 'lng' => 4.0, 'url' => '/apps/maps/#map=16/52/4']],
			$service->getAvailablePois('town')
		);
		$this->assertSame([1, 2], array_column($service->getAvailablePois(''), 'id'));
		$this->assertNull($service->getAvailablePois(null)[1]['category']);
	}//end testThePickerFiltersByIdAndName()

	/**
	 * A failing Maps list is logged and the picker is empty; without a user Maps is not asked.
	 *
	 * @return void
	 */
	public function testThePickerIsEmptyWhenMapsFailsOrNobodyIsLoggedIn(): void {
		$this->favorites->answer = new RuntimeException('db');
		$this->logger->expects($this->once())->method('warning')->with('MapLinkService::getAvailablePois failed: db');

		$this->assertSame([], $this->service()->getAvailablePois());

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->userSession = $session;
		$this->favorites->calls = [];

		$this->assertSame([], $this->service()->getAvailablePois());
		$this->assertSame([], $this->favorites->calls);
	}//end testThePickerIsEmptyWhenMapsFailsOrNobodyIsLoggedIn()

	/**
	 * A container that cannot build the Maps service makes Maps unavailable for creating (503), with a debug line.
	 *
	 * @return void
	 */
	public function testAnUnresolvableMapsServiceIsUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));
		$this->container = $container;
		$this->logger->expects($this->once())->method('debug')->with('MapLinkService: FavoritesService unavailable: not registered');

		$this->expectExceptionCode(503);
		$this->service()->createAndLinkPoi(new LinkTarget(objectUuid: 'obj-2', registerId: 3), new PoiDraft(name: 'Depot'));
	}//end testAnUnresolvableMapsServiceIsUnavailable()

	/**
	 * Linked POIs carry a deep link built from their stored position.
	 *
	 * @return void
	 */
	public function testLinkedPoisCarryADeepLink(): void {
		$link = new MapLink();
		$link->setLat(1.5);
		$link->setLng(2.5);
		$this->mapper->method('findByObjectUuid')->with('obj-1')->willReturn([$link]);

		$rows = $this->service()->getLinkedPois('obj-1');

		$this->assertCount(1, $rows);
		$this->assertSame('/apps/maps/#map=16/1.5/2.5', $rows[0]['url']);
	}//end testLinkedPoisCarryADeepLink()
	/**
	 * Maps disabled: linking is a 503 before any lookup, and the picker never resolves the service.
	 *
	 * @return void
	 */
	public function testDisabledMapsRefusesLinkingAndEmptiesThePicker(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);
		$this->appManager = $apps;
		$this->mapper->expects($this->never())->method('findByObjectAndFavorite');
		$service = $this->service();

		$this->assertSame([], $service->getAvailablePois());
		$this->assertFalse($service->isMapsAvailable());
		$this->expectExceptionCode(503);
		$service->linkPoi(new LinkTarget(objectUuid: 'obj-1', registerId: 3), 12);
	}//end testDisabledMapsRefusesLinkingAndEmptiesThePicker()

	/**
	 * Linking a POI already linked to the object is a 409 and Maps is not asked.
	 *
	 * @return void
	 */
	public function testAnAlreadyLinkedPoiIsAConflict(): void {
		$this->mapper->method('findByObjectAndFavorite')->with('obj-1', 12)->willReturn(new MapLink());
		$this->mapper->expects($this->never())->method('insert');

		try {
			$this->service()->linkPoi(new LinkTarget(objectUuid: 'obj-1', registerId: 3), 12);
			$this->fail('expected a conflict');
		} catch (Exception $e) {
			$this->assertSame([409, 'POI already linked to this object'], [$e->getCode(), $e->getMessage()]);
		}

		$this->assertSame([], $this->favorites->calls);
	}//end testAnAlreadyLinkedPoiIsAConflict()

	/**
	 * Unlinking needs a user, and a delete that removed nothing is a 404; one that removed a row returns quietly.
	 *
	 * @return void
	 */
	public function testUnlinkingNeedsAUserAndARow(): void {
		$this->mapper->expects($this->exactly(2))->method('deleteByObjectAndFavorite')->with('obj-1', 12)->willReturnOnConsecutiveCalls(1, 0);
		$service = $this->service();
		$service->unlinkPoi('obj-1', 12);

		try {
			$service->unlinkPoi('obj-1', 12);
			$this->fail('expected not found');
		} catch (Exception $e) {
			$this->assertSame([404, 'Map link not found'], [$e->getCode(), $e->getMessage()]);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->userSession = $session;
		$this->expectExceptionCode(401);
		$this->service()->unlinkPoi('obj-1', 12);
	}//end testUnlinkingNeedsAUserAndARow()
}//end class
