<?php

/**
 * Unit tests for MapsProvider.
 *
 * Covers:
 *  - metadata matches the leaf spec (id / label / icon / group /
 *    requiredApp / storage strategy)
 *  - isEnabled() mirrors IAppManager::isInstalled('maps')
 *  - list() runs a cross-user `category = "or:{uuid}"` query and
 *    normalises rows into the registry leaf-row contract (happy)
 *  - list() returns an empty list when the app is absent
 *    (absent-app, no DB call at all)
 *  - list() returns an empty list when the tagged-favorites query
 *    yields no rows (empty)
 *  - list() returns an empty list on a DB Throwable (per AD-23 the
 *    provider never raises 5xx on an upstream hiccup)
 *  - health() reports `ok` / `unavailable` symmetrically with
 *    isEnabled()
 *  - mutation methods inherit NotImplementedException from
 *    AbstractIntegrationProvider (read-only iteration)
 *
 * The test file declares a stub `OCA\Maps\Service\FavoritesService`
 * class at module-load time so the provider's `class_exists()` guard
 * inside `list()` resolves to true under unit-test conditions
 * (the real class is in `custom_apps/maps/` and is not on the unit
 * autoloader). Tests for the disabled paths drive `isEnabled() ===
 * false` rather than the class-exists branch.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
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
 * @spec openspec/changes/integration-maps/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test method names + arrange/act/assert structure make intent obvious; matches LeafProvidersMetadataTest convention.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers (assertSame, assertNull, ...) take positional args by convention; mirroring LeafProvidersMetadataTest in this repo.

use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\Integration\Providers\MapsProvider;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for MapsProvider.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class MapsProviderTest extends TestCase
{

    /**
     * Mocked DB connection.
     *
     * @var IDBConnection&\PHPUnit\Framework\MockObject\MockObject
     */
    private $db;

    /**
     * Mocked app manager.
     *
     * @var IAppManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private $appManager;

    /**
     * Mocked localisation.
     *
     * @var IL10N&\PHPUnit\Framework\MockObject\MockObject
     */
    private $l10n;

    /**
     * Declare a stub `OCA\Maps\Service\FavoritesService` so the
     * provider's `class_exists()` guard inside `list()` is satisfied
     * under unit tests. The real class lives in `custom_apps/maps/`
     * and is not on the unit autoloader. The stub is empty —
     * MapsProvider's current iteration never instantiates it.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (class_exists('OCA\\Maps\\Service\\FavoritesService') === false) {
            eval('namespace OCA\\Maps\\Service; class FavoritesService {}');
        }
    }//end setUpBeforeClass()

    protected function setUp(): void
    {
        parent::setUp();
        $this->db         = $this->createMock(IDBConnection::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->l10n       = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);
    }//end setUp()

    /**
     * Build a provider with the given Maps-installed flag.
     *
     * @param bool $mapsInstalled Whether to report 'maps' as installed.
     *
     * @return MapsProvider
     */
    private function buildProvider(bool $mapsInstalled): MapsProvider
    {
        $this->appManager->method('isInstalled')
            ->willReturnCallback(
                static fn(string $appId): bool => $appId === 'maps' && $mapsInstalled
            );

        return new MapsProvider(
            db: $this->db,
            appManager: $this->appManager,
            l10n: $this->l10n,
        );
    }//end buildProvider()

    /**
     * Wire up the IDBConnection mock to return a QueryBuilder whose
     * `executeQuery()` yields the given fetch rows in order, followed
     * by `false` to terminate the iteration.
     *
     * @param array<int,array<string,mixed>|false> $fetchSequence Rows then false.
     *
     * @return void
     */
    private function stubQueryBuilder(array $fetchSequence): void
    {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('expr-eq');

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnOnConsecutiveCalls(...$fetchSequence);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);
    }//end stubQueryBuilder()

    public function testMetadataMatchesLeafSpec(): void
    {
        $provider = $this->buildProvider(mapsInstalled: true);

        $this->assertSame('maps', $provider->getId());
        $this->assertSame('Location', $provider->getLabel());
        $this->assertSame('MapMarker', $provider->getIcon());
        $this->assertSame('docs', $provider->getGroup());
        $this->assertSame('maps', $provider->getRequiredApp());
        $this->assertSame('link-table', $provider->getStorageStrategy());
        $this->assertNull($provider->getOpenConnectorSource());
        $this->assertNull($provider->requiresPermission());
        $this->assertSame(['type' => 'none'], $provider->authRequirements());
    }//end testMetadataMatchesLeafSpec()

    public function testIsEnabledMirrorsAppManager(): void
    {
        $this->assertTrue($this->buildProvider(mapsInstalled: true)->isEnabled());
    }//end testIsEnabledMirrorsAppManager()

    public function testIsEnabledFalseWhenAppMissing(): void
    {
        $this->assertFalse($this->buildProvider(mapsInstalled: false)->isEnabled());
    }//end testIsEnabledFalseWhenAppMissing()

    public function testListReturnsEmptyWhenAppMissingWithoutTouchingDb(): void
    {
        $provider = $this->buildProvider(mapsInstalled: false);

        // DB must not be queried when the app gate fails — assert by
        // making any QB access fatal.
        $this->db->expects($this->never())->method('getQueryBuilder');

        $this->assertSame([], $provider->list('reg', 'sch', 'object-uuid-1'));
    }//end testListReturnsEmptyWhenAppMissingWithoutTouchingDb()

    public function testListReturnsNormalisedRowsForTaggedFavorites(): void
    {
        $this->stubQueryBuilder(
            fetchSequence: [
                [
                    'id'       => 7,
                    'name'     => 'Town Hall',
                    'lat'      => '52.3702',
                    'lng'      => '4.8952',
                    'category' => 'or:object-uuid-1',
                    'comment'  => 'Front entrance',
                    'user_id'  => 'alice',
                ],
                [
                    'id'       => 8,
                    'name'     => 'Library',
                    'lat'      => '52.3721',
                    'lng'      => '4.8985',
                    'category' => 'or:object-uuid-1',
                    'comment'  => '',
                    'user_id'  => 'bob',
                ],
                false,
            ]
        );

        $provider = $this->buildProvider(mapsInstalled: true);
        $rows     = $provider->list('reg', 'sch', 'object-uuid-1');

        $this->assertCount(2, $rows);
        $this->assertSame('7', $rows[0]['id']);
        $this->assertSame('Town Hall', $rows[0]['title']);
        $this->assertSame('/index.php/apps/maps/#/m=7', $rows[0]['url']);
        $this->assertSame(7, $rows[0]['data']['id']);
        $this->assertSame(52.3702, $rows[0]['data']['lat']);
        $this->assertSame(4.8952, $rows[0]['data']['lng']);
        $this->assertSame('or:object-uuid-1', $rows[0]['data']['category']);
        $this->assertSame('alice', $rows[0]['data']['userId']);

        $this->assertSame('8', $rows[1]['id']);
        $this->assertSame('Library', $rows[1]['title']);
        $this->assertSame('bob', $rows[1]['data']['userId']);
    }//end testListReturnsNormalisedRowsForTaggedFavorites()

    public function testListReturnsEmptyWhenNoFavoritesTagged(): void
    {
        $this->stubQueryBuilder(fetchSequence: [false]);

        $provider = $this->buildProvider(mapsInstalled: true);
        $this->assertSame([], $provider->list('reg', 'sch', 'object-uuid-1'));
    }//end testListReturnsEmptyWhenNoFavoritesTagged()

    public function testListSurfacesEmptyOnDbFailure(): void
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willThrowException(new RuntimeException('schema mismatch'));

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $provider = $this->buildProvider(mapsInstalled: true);
        $this->assertSame([], $provider->list('reg', 'sch', 'object-uuid-1'));
    }//end testListSurfacesEmptyOnDbFailure()

    public function testHealthReportsOkWhenAppInstalled(): void
    {
        $health = $this->buildProvider(mapsInstalled: true)->health();
        $this->assertSame('ok', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertNull($health['message']);
    }//end testHealthReportsOkWhenAppInstalled()

    public function testHealthReportsUnavailableWhenAppMissing(): void
    {
        $health = $this->buildProvider(mapsInstalled: false)->health();
        $this->assertSame('unavailable', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertNotEmpty($health['message']);
    }//end testHealthReportsUnavailableWhenAppMissing()

    public function testCreateThrowsNotImplementedException(): void
    {
        $provider = $this->buildProvider(mapsInstalled: true);
        $this->expectException(NotImplementedException::class);
        $provider->create('reg', 'sch', 'obj', ['name' => 'New POI']);
    }//end testCreateThrowsNotImplementedException()

    public function testUpdateThrowsNotImplementedException(): void
    {
        $provider = $this->buildProvider(mapsInstalled: true);
        $this->expectException(NotImplementedException::class);
        $provider->update('reg', 'sch', 'obj', 'fav-1', ['name' => 'Renamed']);
    }//end testUpdateThrowsNotImplementedException()

    public function testDeleteThrowsNotImplementedException(): void
    {
        $provider = $this->buildProvider(mapsInstalled: true);
        $this->expectException(NotImplementedException::class);
        $provider->delete('reg', 'sch', 'obj', 'fav-1');
    }//end testDeleteThrowsNotImplementedException()
}//end class
