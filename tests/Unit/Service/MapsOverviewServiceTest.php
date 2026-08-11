<?php

/**
 * Unit tests for MapsOverviewService — page-level multi-object MAP render
 * surface (procest "cases on map", issue #112).
 *
 * Covers the contract required by the integration-maps-overview change:
 *   - registerOverview(): declares a `map` page widget on the registry with
 *     the register/schema scope + declarative base layer (PDOK default,
 *     overridable); rejects empty key / register / schema; the write path
 *     requires an authenticated user.
 *   - queryPoints(): runs the canonical OR read path with _rbac:true for
 *     non-admins (anonymous sees only public-group-readable objects),
 *     extracts {id,label,lat,lng,...} markers from object geometry, skips
 *     geometry-less objects, and caps the read.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-maps-overview-page-surface/specs/integration-maps-overview/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Geo\GeoFeatureCollectionBuilder;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\MapsOverviewService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MapsOverviewService.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MapsOverviewServiceTest extends TestCase
{
    private function buildUserSession(?string $uid): IUserSession
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $session->method('getUser')->willReturn(null);
            return $session;
        }

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session->method('getUser')->willReturn($user);
        return $session;
    }//end buildUserSession()


    private function buildGroupManager(bool $isAdmin): IGroupManager
    {
        $gm = $this->createMock(IGroupManager::class);
        $gm->method('isAdmin')->willReturn($isAdmin);
        return $gm;
    }//end buildGroupManager()


    private function buildRegistry(): IntegrationRegistry
    {
        return new IntegrationRegistry(logger: $this->createMock(LoggerInterface::class));
    }//end buildRegistry()


    /**
     * registerOverview() declares a `map` page widget with the PDOK default
     * base layer when none is supplied.
     *
     * @return void
     */
    public function testRegisterDeclaresMapWidgetWithDefaultBaseLayer(): void
    {
        $registry = $this->buildRegistry();

        $service = new MapsOverviewService(
            objectService: $this->createMock(ObjectService::class),
            geoBuilder: new GeoFeatureCollectionBuilder(),
            registry: $registry,
            userSession: $this->buildUserSession('alice'),
            groupManager: $this->buildGroupManager(false),
        );

        $result = $service->registerOverview(
            overviewKey: 'cases-on-map',
            register: 'zaakregister',
            schema: 'zaak',
            title: 'Cases on map'
        );

        $this->assertSame('maps-overview:cases-on-map', $result['id']);
        $this->assertSame('map', $result['type']);
        $this->assertSame('maps-overview', $result['providerId']);
        $this->assertSame('cases-on-map', $result['config']['overviewKey']);
        $this->assertSame('zaakregister', $result['config']['register']);
        $this->assertSame('zaak', $result['config']['schema']);
        // PDOK base layer is declarative metadata, not hardcoded in renderer.
        $this->assertSame('pdok', $result['config']['baseLayer']['provider']);
        $this->assertSame('wmts', $result['config']['baseLayer']['type']);

        $widget = $registry->getPageWidget('maps-overview:cases-on-map');
        $this->assertNotNull($widget, 'map page widget must be on the registry');
        $this->assertSame('map', $widget['type']);
    }//end testRegisterDeclaresMapWidgetWithDefaultBaseLayer()


    /**
     * registerOverview() honours a declarative base-layer override.
     *
     * @return void
     */
    public function testRegisterHonoursBaseLayerOverride(): void
    {
        $service = new MapsOverviewService(
            objectService: $this->createMock(ObjectService::class),
            geoBuilder: new GeoFeatureCollectionBuilder(),
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('alice'),
            groupManager: $this->buildGroupManager(false),
        );

        $custom = ['type' => 'xyz', 'provider' => 'osm', 'url' => 'https://tile.osm.org/{z}/{x}/{y}.png'];
        $result = $service->registerOverview(
            overviewKey: 'osm-map',
            register: '1',
            schema: '2',
            baseLayer: $custom
        );

        $this->assertSame('osm', $result['config']['baseLayer']['provider']);
        $this->assertSame('xyz', $result['config']['baseLayer']['type']);
    }//end testRegisterHonoursBaseLayerOverride()


    /**
     * registerOverview() rejects an empty key.
     *
     * @return void
     */
    public function testRegisterRejectsEmptyKey(): void
    {
        $service = new MapsOverviewService(
            objectService: $this->createMock(ObjectService::class),
            geoBuilder: new GeoFeatureCollectionBuilder(),
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('alice'),
            groupManager: $this->buildGroupManager(false),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->registerOverview(overviewKey: '', register: '1', schema: '2');
    }//end testRegisterRejectsEmptyKey()


    /**
     * registerOverview() rejects a missing register / schema.
     *
     * @return void
     */
    public function testRegisterRejectsMissingScope(): void
    {
        $service = new MapsOverviewService(
            objectService: $this->createMock(ObjectService::class),
            geoBuilder: new GeoFeatureCollectionBuilder(),
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('alice'),
            groupManager: $this->buildGroupManager(false),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->registerOverview(overviewKey: 'k', register: '', schema: '2');
    }//end testRegisterRejectsMissingScope()


    /**
     * ensureCanRegister() rejects an anonymous caller (fail-closed write).
     *
     * @return void
     */
    public function testEnsureCanRegisterRejectsAnonymous(): void
    {
        $service = new MapsOverviewService(
            objectService: $this->createMock(ObjectService::class),
            geoBuilder: new GeoFeatureCollectionBuilder(),
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession(null),
            groupManager: $this->buildGroupManager(false),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->ensureCanRegister();
    }//end testEnsureCanRegisterRejectsAnonymous()


    /**
     * queryPoints() returns {id,label,lat,lng,...} markers for objects that
     * carry a geometry, and skips geometry-less objects.
     *
     * @return void
     */
    public function testQueryPointsExtractsMarkersAndSkipsGeometryless(): void
    {
        $rows = [
            // A point object — becomes a marker.
            [
                'id'       => 'obj-1',
                '@self'    => ['id' => 'obj-1', 'name' => 'Case 1'],
                'location' => ['type' => 'Point', 'coordinates' => [5.12, 52.09]],
            ],
            // A polygon object — first vertex becomes the marker.
            [
                'id'    => 'obj-2',
                '@self' => ['id' => 'obj-2', 'title' => 'Case 2'],
                'area'  => ['type' => 'Polygon', 'coordinates' => [[[4.9, 52.3], [5.0, 52.3], [5.0, 52.4], [4.9, 52.3]]]],
            ],
            // No geometry — skipped.
            [
                'id'    => 'obj-3',
                '@self' => ['id' => 'obj-3', 'name' => 'No location'],
                'note'  => 'nothing to plot',
            ],
        ];

        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->once())
            ->method('findAll')
            ->willReturn($rows);

        $service = new MapsOverviewService(
            objectService: $objectService,
            geoBuilder: new GeoFeatureCollectionBuilder(),
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('alice'),
            groupManager: $this->buildGroupManager(false),
        );

        $points = $service->queryPoints(register: 'zaakregister', schema: 'zaak');

        $this->assertCount(2, $points, 'geometry-less object must be skipped');

        $this->assertSame('obj-1', $points[0]['id']);
        $this->assertSame('Case 1', $points[0]['label']);
        $this->assertSame(52.09, $points[0]['lat']);
        $this->assertSame(5.12, $points[0]['lng']);
        $this->assertSame('zaakregister', $points[0]['register']);
        $this->assertSame('zaak', $points[0]['schema']);

        $this->assertSame('obj-2', $points[1]['id']);
        $this->assertSame('Case 2', $points[1]['label']);
        // First vertex of the polygon ring: [4.9, 52.3] = lng,lat.
        $this->assertSame(52.3, $points[1]['lat']);
        $this->assertSame(4.9, $points[1]['lng']);
    }//end testQueryPointsExtractsMarkersAndSkipsGeometryless()


    /**
     * The SAME extraction, against the shape `ObjectService::findAll()`
     * ACTUALLY returns.
     *
     * The test above hands the double a list of plain arrays. `findAll()`
     * never returns those: it returns rendered `ObjectEntity` **objects**
     * (`ObjectService::findAll()` -> `RenderObject::renderEntities()`,
     * declared `@return ObjectEntity[]`). `queryPoints()` skipped every row
     * that was not an array, so the endpoint returned
     * `{"points":[],"count":0}` for every register/schema while this suite
     * stayed green — the double encoded the bug.
     *
     * A test double is a claim about a collaborator's contract. This one
     * asserts the real contract, so it fails if `queryPoints()` ever goes
     * back to array-only handling.
     *
     * @return void
     */
    public function testQueryPointsReadsTheObjectEntitiesFindAllActuallyReturns(): void
    {
        $point = new ObjectEntity();
        $point->setUuid('obj-1');
        $point->setName('Case 1');
        $point->setObject(['location' => ['type' => 'Point', 'coordinates' => [5.12, 52.09]]]);

        $geometryless = new ObjectEntity();
        $geometryless->setUuid('obj-3');
        $geometryless->setName('No location');
        $geometryless->setObject(['note' => 'nothing to plot']);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->once())
            ->method('findAll')
            ->willReturn([$point, $geometryless]);

        $service = new MapsOverviewService(
            objectService: $objectService,
            geoBuilder: new GeoFeatureCollectionBuilder(),
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('alice'),
            groupManager: $this->buildGroupManager(false),
        );

        $points = $service->queryPoints(register: 'zaakregister', schema: 'zaak');

        $this->assertCount(1, $points, 'the ObjectEntity carrying geometry must produce a marker');
        $this->assertSame('obj-1', $points[0]['id']);
        $this->assertSame(52.09, $points[0]['lat']);
        $this->assertSame(5.12, $points[0]['lng']);
    }//end testQueryPointsReadsTheObjectEntitiesFindAllActuallyReturns()


    /**
     * queryPoints() runs the OR read path with `_rbac: true` for a
     * non-admin caller (anonymous included) — so it can only ever see what
     * the public group may read (ADR-005, fail-closed). An admin reads with
     * `_rbac: false`.
     *
     * @return void
     */
    public function testQueryPointsEnforcesRbacForNonAdmin(): void
    {
        $captured = null;

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config, bool $_rbac=true, bool $_multitenancy=true) use (&$captured): array {
                $captured = ['rbac' => $_rbac, 'multi' => $_multitenancy, 'config' => $config];
                return [];
            }
        );

        // Anonymous caller — must scope with _rbac: true.
        $service = new MapsOverviewService(
            objectService: $objectService,
            geoBuilder: new GeoFeatureCollectionBuilder(),
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession(null),
            groupManager: $this->buildGroupManager(false),
        );

        $service->queryPoints(register: 'zaakregister', schema: 'zaak');

        $this->assertNotNull($captured);
        $this->assertTrue($captured['rbac'], 'anonymous read MUST be RBAC-scoped (fail-closed)');
        $this->assertTrue($captured['multi'], 'anonymous read MUST be multitenancy-scoped');
        // register/schema scope keys are mandatory and caller-immutable.
        $this->assertSame('zaakregister', $captured['config']['filters']['register']);
        $this->assertSame('zaak', $captured['config']['filters']['schema']);
    }//end testQueryPointsEnforcesRbacForNonAdmin()


    /**
     * queryPoints() reads with `_rbac: false` for an admin caller (full
     * read, RBAC bypass — same posture as the objects index).
     *
     * @return void
     */
    public function testQueryPointsBypassesRbacForAdmin(): void
    {
        $captured = null;

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config, bool $_rbac=true, bool $_multitenancy=true) use (&$captured): array {
                $captured = ['rbac' => $_rbac];
                return [];
            }
        );

        $service = new MapsOverviewService(
            objectService: $objectService,
            geoBuilder: new GeoFeatureCollectionBuilder(),
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('root'),
            groupManager: $this->buildGroupManager(true),
        );

        $service->queryPoints(register: '1', schema: '2');

        $this->assertNotNull($captured);
        $this->assertFalse($captured['rbac'], 'admin read bypasses RBAC');
    }//end testQueryPointsBypassesRbacForAdmin()


    /**
     * queryPoints() refuses caller attempts to override the register/schema
     * scope keys through the filter bag (scope is owned by the service).
     *
     * @return void
     */
    public function testQueryPointsScopeKeysAreCallerImmutable(): void
    {
        $captured = null;

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config, bool $_rbac=true, bool $_multitenancy=true) use (&$captured): array {
                $captured = $config;
                return [];
            }
        );

        $service = new MapsOverviewService(
            objectService: $objectService,
            geoBuilder: new GeoFeatureCollectionBuilder(),
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('alice'),
            groupManager: $this->buildGroupManager(false),
        );

        $service->queryPoints(
            register: 'real-register',
            schema: 'real-schema',
            filters: ['register' => 'spoofed', 'schema' => 'spoofed', 'status' => 'open']
        );

        $this->assertSame('real-register', $captured['filters']['register']);
        $this->assertSame('real-schema', $captured['filters']['schema']);
        // A legitimate narrowing filter is passed through.
        $this->assertSame('open', $captured['filters']['status']);
    }//end testQueryPointsScopeKeysAreCallerImmutable()


    /**
     * queryPoints() rejects an empty register / schema.
     *
     * @return void
     */
    public function testQueryPointsRejectsMissingScope(): void
    {
        $service = new MapsOverviewService(
            objectService: $this->createMock(ObjectService::class),
            geoBuilder: new GeoFeatureCollectionBuilder(),
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('alice'),
            groupManager: $this->buildGroupManager(false),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->queryPoints(register: '', schema: 'zaak');
    }//end testQueryPointsRejectsMissingScope()
}//end class
