<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MapLink;
use OCA\OpenRegister\Db\MapLinkMapper;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\MapLinkService;
use OCA\OpenRegister\Service\PoiDraft;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * A class name the service can class_exists() on; the container hands out the fake.
 */
class MapLinkServiceRefactorGoldenTestClassStub {
}

if (class_exists('OCA\\Maps\\Service\\FavoritesService') === false) {
	class_alias(MapLinkServiceRefactorGoldenTestClassStub::class, 'OCA\\Maps\\Service\\FavoritesService');
}

/**
 * NC Maps' FavoritesService, answering from a map; a Throwable answer is thrown.
 */
class MapLinkServiceRefactorGoldenTestFavorites {

	/**
	 * Calls received.
	 *
	 * @var list<array<int, mixed>>
	 */
	public static array $log = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $answers Method => answer.
	 */
	public function __construct(private array $answers) {
	}

	/**
	 * Answer a call.
	 *
	 * @param string $name Method.
	 * @param array  $args Arguments.
	 *
	 * @return mixed
	 */
	private function answer(string $name, array $args): mixed {
		self::$log[] = [$name, $args];
		$answer = $this->answers[$name];
		if ($answer instanceof Throwable) {
			throw $answer;
		}

		return $answer;
	}

	/** @return mixed */
	public function addFavoriteToDB(...$args) {
		return $this->answer('addFavoriteToDB', $args);
	}

	/** @return mixed */
	public function getFavoritesFromDB(...$args) {
		return $this->answer('getFavoritesFromDB', $args);
	}

	/** @return mixed */
	public function getFavoriteFromDB(...$args) {
		return $this->answer('getFavoriteFromDB', $args);
	}
}

/**
 * Pins what the MapLinkService operations return and do to the mapper, NC Maps
 * and the log, per scenario, against fixtures/map-link-refactor-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite it. linkedAt (the wall clock) is masked.
 *
 * @covers \OCA\OpenRegister\Service\MapLinkService
 */
class MapLinkServiceRefactorGoldenTest extends TestCase {

	/**
	 * Collaborator calls.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Build the service.
	 *
	 * @param array $opt Options: maps (bool), user (?string), favorites (?object|Throwable), existing (bool), links (list<MapLink>).
	 *
	 * @return MapLinkService
	 */
	private function service(array $opt): MapLinkService {
		$mapper = $this->getMockBuilder(MapLinkMapper::class)->disableOriginalConstructor()
			->onlyMethods(['findByObjectAndFavorite', 'findByObjectUuid', 'insert', 'deleteByObjectAndFavorite'])->getMock();
		$mapper->method('findByObjectAndFavorite')->willReturn(($opt['existing'] ?? false) === true ? new MapLink() : null);
		$mapper->method('findByObjectUuid')->willReturn($opt['links'] ?? []);
		$mapper->method('deleteByObjectAndFavorite')->willReturn($opt['deleted'] ?? 1);
		$mapper->method('insert')->willReturnCallback(
			function (MapLink $link) {
				$json = $link->jsonSerialize();
				$json['linkedAt'] = '<NOW>';
				$this->calls[] = ['insert', $json, array_keys($link->getUpdatedFields())];
				return $link;
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($opt['maps'] ?? true);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if (array_key_exists('user', $opt) === false || $opt['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($opt['user'] ?? 'alice');
		}
		$session->method('getUser')->willReturn($user);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($opt) {
				$this->calls[] = ['container', $id];
				$favorites = ($opt['favorites'] ?? new RuntimeException('no maps'));
				if ($favorites instanceof Throwable) {
					throw $favorites;
				}
				return $favorites;
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/apps/maps/');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message) use ($level) {
					$this->calls[] = [$level, $message];
				}
			);
		}

		return new MapLinkService($mapper, $container, $apps, $session, $urls, $logger);
	}//end service()

	/**
	 * A stored link.
	 *
	 * @param mixed $lat Latitude.
	 * @param mixed $lng Longitude.
	 *
	 * @return MapLink
	 */
	private static function link(mixed $lat, mixed $lng): MapLink {
		$link = new MapLink();
		$link->setId(4);
		$link->setFavoriteId(9);
		$link->setName('Gemeentehuis');
		$link->setLat($lat);
		$link->setLng($lng);
		return $link;
	}

	/**
	 * Scenario table: name => closure(self): mixed.
	 *
	 * @return array<string, array{\Closure}>
	 */
	public static function scenarios(): array {
		$target = (new LinkTarget('obj-1', 3))->inSchema(4);
		$fav = fn (array $answers) => new MapLinkServiceRefactorGoldenTestFavorites($answers);
		$boom = new RuntimeException('boom');
		$full = ['id' => 9, 'name' => 'Gemeentehuis', 'category' => 12, 'lat' => '52.1', 'lng' => 5, 'comment' => 7];
		$poi = (new PoiDraft('  Stadhuis  '))->locatedAt(52.37, 4.89)->withNotes('Werk', 'Ingang links');
		$bare = new PoiDraft('Plek');

		return [
			'link: no user' => [fn (self $t) => $t->service(['user' => null])->linkPoi($target, 9)],
			'link: maps off' => [fn (self $t) => $t->service(['maps' => false])->linkPoi($target, 9)],
			'link: duplicate' => [fn (self $t) => $t->service(['existing' => true])->linkPoi($target, 9)],
			'link: no favorites service' => [fn (self $t) => $t->service([])->linkPoi($target, 9)],
			'link: lookup throws' => [fn (self $t) => $t->service(['favorites' => $fav(['getFavoriteFromDB' => $boom])])->linkPoi($target, 9)],
			'link: lookup not array' => [fn (self $t) => $t->service(['favorites' => $fav(['getFavoriteFromDB' => null])])->linkPoi($target, 9)],
			'link: full favorite' => [fn (self $t) => $t->service(['favorites' => $fav(['getFavoriteFromDB' => $full])])->linkPoi($target, 9)],
			'link: empty favorite' => [fn (self $t) => $t->service(['favorites' => $fav(['getFavoriteFromDB' => []])])->linkPoi($target, 9)],
			'create: no user' => [fn (self $t) => $t->service(['user' => null])->createAndLinkPoi($target, $poi)],
			'create: blank name' => [fn (self $t) => $t->service([])->createAndLinkPoi($target, new PoiDraft('   '))],
			'create: maps off' => [fn (self $t) => $t->service(['maps' => false])->createAndLinkPoi($target, $poi)],
			'create: add throws' => [fn (self $t) => $t->service(['favorites' => $fav(['addFavoriteToDB' => $boom])])->createAndLinkPoi($target, $poi)],
			'create: add returns zero' => [fn (self $t) => $t->service(['favorites' => $fav(['addFavoriteToDB' => '0'])])->createAndLinkPoi($target, $poi)],
			'create: full' => [fn (self $t) => $t->service(['favorites' => $fav(['addFavoriteToDB' => '31'])])->createAndLinkPoi($target, $poi)],
			'create: bare draft' => [fn (self $t) => $t->service(['favorites' => $fav(['addFavoriteToDB' => 32])])->createAndLinkPoi($target, $bare)],
			'unlink: done' => [fn (self $t) => $t->service([])->unlinkPoi('obj-1', 9)],
			'unlink: missing' => [fn (self $t) => $t->service(['deleted' => 0])->unlinkPoi('obj-1', 9)],
			'linked' => [fn (self $t) => $t->service(['links' => [self::link(52.5, 4.25), self::link(null, null)]])->getLinkedPois('obj-1')],
			'available: maps off' => [fn (self $t) => $t->service(['maps' => false])->getAvailablePois()],
			'available: no user' => [fn (self $t) => $t->service(['user' => null, 'favorites' => $fav([])])->getAvailablePois()],
			'available: list throws' => [fn (self $t) => $t->service(['favorites' => $fav(['getFavoritesFromDB' => $boom])])->getAvailablePois()],
			'available: rows' => [fn (self $t) => $t->service(['favorites' => $fav(['getFavoritesFromDB' => [
				$full,
				['id' => 0, 'name' => 'Zero'],
				['name' => 'No id'],
				['id' => '10', 'name' => 'Bakker', 'lat' => 1.5],
				['id' => 11],
			]])])->getAvailablePois()],
			'available: search' => [fn (self $t) => $t->service(['favorites' => $fav(['getFavoritesFromDB' => [$full, ['id' => 10, 'name' => 'GEMEENTE werf'], ['id' => 12, 'name' => 'Bakker']]])])->getAvailablePois('gemeente')],
			'available: empty search' => [fn (self $t) => $t->service(['favorites' => $fav(['getFavoritesFromDB' => [['id' => 12, 'name' => 'Bakker']]])])->getAvailablePois('')],
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
		MapLinkServiceRefactorGoldenTestFavorites::$log = [];
		try {
			$value = $run($this);
			if ($value instanceof MapLink) {
				$value = 'inserted';
			}
			$result = ['return' => $value];
		} catch (\Exception $e) {
			$result = ['exception' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
		}

		$result['calls'] = $this->calls;
		$result['maps'] = MapLinkServiceRefactorGoldenTestFavorites::$log;
		$actual = json_decode(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);

		$file = __DIR__ . '/fixtures/map-link-refactor-golden.json';
		$name = $this->dataName();
		$all = [];
		if (is_file($file) === true) {
			$all = json_decode(file_get_contents($file), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertSame($all[$name], $actual);
	}//end testGolden()
}//end class
