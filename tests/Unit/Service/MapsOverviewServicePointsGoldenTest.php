<?php

/**
 * Golden test pinning MapsOverviewService's point query: access scope,
 * marker cap, row filtering and the scope refusals.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\Rbac\CallerContext;
use InvalidArgumentException;
use OCA\OpenRegister\Search\Service\Geo\GeoFeatureCollectionBuilder;
use OCA\OpenRegister\Search\Service\MapMarkerSource;
use OCA\OpenRegister\Search\Service\MapsOverviewService;
use OCA\OpenRegister\Search\Service\MapsOverviewSources;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Search\Service\MapOverview;

/**
 * Allowed and refused outcomes of queryPoints / declareOverview.
 *
 * @covers \OCA\OpenRegister\Search\Service\MapsOverviewService
 */
final class MapsOverviewServicePointsGoldenTest extends TestCase {

	/**
	 * Captured findAll calls.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $finds = [];

	/**
	 * Build the service.
	 *
	 * @param string|null $uid     Logged-in user, or null for anonymous
	 * @param bool        $isAdmin Whether the user is an admin
	 * @param array       $rows    Rows findAll returns
	 *
	 * @return MapsOverviewService
	 */
	private function service(?string $uid, bool $isAdmin, array $rows = []): MapsOverviewService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true, bool $_multitenancy = true) use ($rows): array {
				$this->finds[] = ['limit' => $config['limit'], 'filters' => $config['filters'], 'rbac' => $_rbac, 'multi' => $_multitenancy];
				return $rows;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $id): bool => $isAdmin && $id === $uid);

		return new MapsOverviewService(
			new MapsOverviewSources($objects, new GeoFeatureCollectionBuilder(), new IntegrationRegistry($this->createMock(LoggerInterface::class))),
			new CallerContext($session, $groups)
		);
	}//end service()

	/**
	 * Limit values and the cap sent to findAll.
	 *
	 * @return array<string, array<int, int|null>>
	 */
	public static function limits(): array {
		return [
			'null' => [null, 5000],
			'zero' => [0, 5000],
			'negative' => [-3, 5000],
			'small' => [7, 7],
			'just below' => [4999, 4999],
			'at cap' => [5000, 5000],
			'above cap' => [9000, 5000],
		];
	}//end limits()

	/**
	 * The marker cap.
	 *
	 * @param int|null $limit    Requested limit
	 * @param int      $expected Cap sent to findAll
	 *
	 * @return void
	 */
	#[DataProvider('limits')]
	public function testCap(?int $limit, int $expected): void {
		$this->service('alice', false)->queryPoints(new MapMarkerSource('r', 's', null, []), $limit);
		$this->assertSame($expected, $this->finds[0]['limit']);
	}//end testCap()

	/**
	 * Access scope per caller: anonymous and non-admin are scoped, admin is not.
	 *
	 * @return void
	 */
	public function testAccessScope(): void {
		$this->service(null, false)->queryPoints(new MapMarkerSource('r', 's'));
		$this->service('bob', false)->queryPoints(new MapMarkerSource('r', 's'));
		$this->service('root', true)->ensureReadablePoints(new MapMarkerSource(3, 4, null, ['register' => 'x', 'page' => 2, 'status' => 'open']));

		$this->assertSame(
			[
				['limit' => 5000, 'filters' => ['register' => 'r', 'schema' => 's'], 'rbac' => true, 'multi' => true],
				['limit' => 5000, 'filters' => ['register' => 'r', 'schema' => 's'], 'rbac' => true, 'multi' => true],
				['limit' => 5000, 'filters' => ['status' => 'open', 'register' => 3, 'schema' => 4], 'rbac' => false, 'multi' => false],
			],
			$this->finds
		);
	}//end testAccessScope()

	/**
	 * Unusable rows and rows without geometry are skipped, in order.
	 *
	 * @return void
	 */
	public function testRowFiltering(): void {
		$rows = [
			'not a row',
			42,
			['id' => 'a', 'location' => ['type' => 'Point', 'coordinates' => [5.1, 52.1]], 'naam' => 'A'],
			['id' => 'b', 'naam' => 'no geometry'],
			['id' => 'c', 'location' => ['type' => 'Point', 'coordinates' => [4.9, 52.3]]],
		];
		$points = $this->service('alice', false, $rows)->queryPoints(new MapMarkerSource('r', 's', 'location', []));

		$this->assertSame(['a', 'c'], array_column($points, 'id'));
		$this->assertSame([52.1, 5.1], [$points[0]['lat'], $points[0]['lng']]);
	}//end testRowFiltering()

	/**
	 * Scope refusals happen before any read.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$outcomes = [];
		$calls = [
			fn (MapsOverviewService $s) => $s->queryPoints(new MapMarkerSource('', 's')),
			fn (MapsOverviewService $s) => $s->queryPoints(new MapMarkerSource('r', '')),
			fn (MapsOverviewService $s) => $s->queryPoints(new MapMarkerSource(0, 0)),
			fn (MapsOverviewService $s) => $s->declareOverview(new MapOverview('  ', new MapMarkerSource('r', 's'))),
			fn (MapsOverviewService $s) => $s->declareOverview(new MapOverview('k', new MapMarkerSource('', 's'))),
			fn (MapsOverviewService $s) => $s->declareOverview(new MapOverview('k', new MapMarkerSource('r', ''))),
			fn (MapsOverviewService $s) => $s->declareOverview(new MapOverview('k', new MapMarkerSource(0, 0)))['id'],
		];
		foreach ($calls as $call) {
			try {
				$result = $call($this->service('root', true));
				$outcomes[] = 'ok:' . (is_array($result) ? count($result) : (string)$result);
			} catch (InvalidArgumentException $e) {
				$outcomes[] = $e->getMessage();
			}
		}

		$this->assertSame(
			[
				'register and schema are required to query map points',
				'register and schema are required to query map points',
				'ok:0',
				'overviewKey is required to register a map overview',
				'register and schema are required to register a map overview',
				'register and schema are required to register a map overview',
				'ok:maps-overview:k',
			],
			$outcomes
		);
		$this->assertCount(1, $this->finds);
	}//end testRefusals()

	/**
	 * Label precedence and representative position across geometry depths.
	 *
	 * @return void
	 */
	public function testLabelsAndPositions(): void {
		$point = ['type' => 'Point', 'coordinates' => [5, 52]];
		$rows = [
			['id' => 1, '@self' => ['name' => 'Self name', 'title' => 'Self title'], 'name' => 'Row', 'g' => $point],
			['id' => 2, '@self' => ['name' => '', 'title' => 'Self title'], 'name' => 'Row', 'g' => $point],
			['id' => 3, '@self' => ['name' => ['x']], 'naam' => 'Naam', 'titel' => 'Titel', 'g' => $point],
			['id' => 4, 'titel' => 0, 'g' => $point],
			['id' => 5, 'g' => $point],
			['@self' => ['id' => 'self-id'], 'g' => ['type' => 'LineString', 'coordinates' => [[4.1, 51.1], [4.2, 51.2]]]],
			['id' => 7, 'g' => ['type' => 'Polygon', 'coordinates' => [[[3.5, 50.5], [3.6, 50.6], [3.5, 50.5]]]]],
			['id' => 8, 'g' => ['type' => 'MultiPolygon', 'coordinates' => [[[['6', '53'], [6.1, 53.1], [6, 53]]]]]],
			['id' => 9, 'g' => ['type' => 'Point', 'coordinates' => ['x', 52]]],
			['id' => 10, 'g' => ['type' => 'Point', 'coordinates' => [5]]],
			['id' => 11, 'g' => ['type' => 'Point', 'coordinates' => []]],
			['id' => 12, 'g' => ['type' => 'Point', 'coordinates' => [[[[[[1, 2]]]]]]]],
		];
		$points = $this->service('alice', false, $rows)->queryPoints(new MapMarkerSource('r', 's', 'g', []));

		$this->assertSame(
			[
				['1', 'Self name', 52.0, 5.0],
				['2', 'Self title', 52.0, 5.0],
				['3', 'Naam', 52.0, 5.0],
				['4', '0', 52.0, 5.0],
				['5', '5', 52.0, 5.0],
				['self-id', 'self-id', 51.1, 4.1],
				['7', '7', 50.5, 3.5],
				['8', '8', 53.0, 6.0],
			],
			array_map(static fn (array $p): array => [$p['id'], $p['label'], $p['lat'], $p['lng']], $points)
		);
	}//end testLabelsAndPositions()
}//end class
