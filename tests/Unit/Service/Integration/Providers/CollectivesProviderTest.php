<?php

/**
 * Unit tests for CollectivesProvider.
 *
 * Covers the three acceptance scenarios called out in
 * `openspec/changes/integration-collectives/proposal.md`:
 *   - happy-path: NC Collectives installed + a linked page exists
 *   - absent-app: NC Collectives not installed → empty list, no throw
 *   - empty-result: NC Collectives installed but no page matches → empty
 *
 * Plus contract coverage that the metadata test in
 * {@see LeafProvidersMetadataTest} doesn't repeat (soft-deleted pages
 * are filtered, slug-without-marker rows are skipped, health()
 * descriptor flips on app availability).
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
 * @spec openspec/changes/integration-collectives/tasks.md
 *
 * @group requires-app-collectives
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test methods are self-documenting via name + arrange/act/assert structure; matches LeafProvidersMetadataTest convention.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Service\Integration\Providers\CollectivesProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CollectivesProvider.
 */
class CollectivesProviderTest extends TestCase
{

    /**
     * Skip the entire suite when the optional NC Collectives app is
     * not loaded in the test environment — those scenarios live with
     * the metadata test in {@see LeafProvidersMetadataTest} which
     * exercises the stub-shape behaviour.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (class_exists(\OCA\Collectives\Db\PageMapper::class) === false
            || class_exists(\OCA\Collectives\Service\CollectiveService::class) === false
        ) {
            $this->markTestSkipped('NC Collectives app not installed in this test environment.');
        }
    }//end setUp()

    /**
     * Build a mocked IL10N that passes strings through unchanged.
     *
     * @return IL10N
     */
    private function buildL10n(): IL10N
    {
        $mock = $this->createMock(IL10N::class);
        $mock->method('t')->willReturnArgument(0);
        return $mock;
    }//end buildL10n()

    /**
     * Build a mocked IAppManager reporting `collectives` as
     * installed (or not, depending on the flag).
     *
     * @param bool $installed Whether to treat `collectives` as installed.
     *
     * @return IAppManager
     */
    private function buildAppManager(bool $installed): IAppManager
    {
        $mock = $this->createMock(IAppManager::class);
        $mock->method('isInstalled')->willReturnCallback(
            static fn(string $appId): bool => ($appId === 'collectives' && $installed === true)
        );
        return $mock;
    }//end buildAppManager()

    /**
     * Build a Collectives Page double seeded with the given attributes.
     *
     * @param int      $id             Page id.
     * @param string   $slug           Slug value (may carry the OR marker).
     * @param int|null $trashTimestamp Trash timestamp or null when live.
     *
     * @return \OCA\Collectives\Db\Page
     */
    private function buildPage(int $id, string $slug, ?int $trashTimestamp=null): \OCA\Collectives\Db\Page
    {
        $page = new \OCA\Collectives\Db\Page();
        $page->setId($id);
        $page->setSlug($slug);
        $page->setFileId(($id + 1000));
        $page->setLastUserId('alice');
        $page->setEmoji('📘');
        if ($trashTimestamp !== null) {
            $page->setTrashTimestamp($trashTimestamp);
        }
        return $page;
    }//end buildPage()

    /**
     * Anonymous-class subclass of CollectivesProvider that lets the
     * test substitute the PageMapper without touching the NC DI
     * container. Preserves the upstream constructor shape so DI in
     * production stays binary-compatible.
     *
     * @param IDBConnection                                   $db         Db conn.
     * @param IAppManager                                     $appManager App manager.
     * @param IL10N                                           $l10n       L10n.
     * @param \OCA\Collectives\Db\PageMapper|null             $stubMapper Stub mapper to use, or null to return null.
     *
     * @return CollectivesProvider Test instance.
     */
    private function buildProviderWithStubMapper(
        IDBConnection $db,
        IAppManager $appManager,
        IL10N $l10n,
        ?\OCA\Collectives\Db\PageMapper $stubMapper,
    ): CollectivesProvider {
        return new class($db, $appManager, $l10n, $stubMapper) extends CollectivesProvider
        {

            public function __construct(
                IDBConnection $db,
                IAppManager $appManager,
                IL10N $l10n,
                private ?\OCA\Collectives\Db\PageMapper $stubMapper,
            ) {
                parent::__construct($db, $appManager, $l10n);
            }//end __construct()

            protected function resolvePageMapper(): ?\OCA\Collectives\Db\PageMapper
            {
                return $this->stubMapper;
            }//end resolvePageMapper()
        };
    }//end buildProviderWithStubMapper()

    public function testListReturnsLinkedPagesWhenAppInstalled(): void
    {
        $objectUuid = 'aaaa-bbbb-cccc';
        $marker     = '[or:'.$objectUuid.']';

        $matchingPage    = $this->buildPage(42, 'team-procedure-'.$marker);
        $secondMatchPage = $this->buildPage(99, $marker.'-policy');
        $unrelatedPage   = $this->buildPage(7, 'random-other-page');

        $stubMapper = $this->createMock(\OCA\Collectives\Db\PageMapper::class);
        $stubMapper->expects($this->once())
            ->method('getAll')
            ->willReturn([$matchingPage, $secondMatchPage, $unrelatedPage]);

        $provider = $this->buildProviderWithStubMapper(
            $this->createMock(IDBConnection::class),
            $this->buildAppManager(installed: true),
            $this->buildL10n(),
            $stubMapper,
        );

        $rows = $provider->list('reg', 'sch', $objectUuid);

        $this->assertCount(2, $rows);
        $this->assertSame('42', $rows[0]['id']);
        $this->assertSame('team-procedure-'.$marker, $rows[0]['title']);
        $this->assertSame('/index.php/apps/collectives/p/42', $rows[0]['url']);
        $this->assertSame('alice', $rows[0]['data']['lastUserId']);
        $this->assertSame('📘', $rows[0]['data']['emoji']);
        $this->assertSame('99', $rows[1]['id']);
    }//end testListReturnsLinkedPagesWhenAppInstalled()

    public function testListReturnsEmptyWhenAppNotInstalled(): void
    {
        // No mapper resolution should happen — the provider must
        // short-circuit on isEnabled() === false.
        $stubMapper = $this->createMock(\OCA\Collectives\Db\PageMapper::class);
        $stubMapper->expects($this->never())->method('getAll');

        $provider = $this->buildProviderWithStubMapper(
            $this->createMock(IDBConnection::class),
            $this->buildAppManager(installed: false),
            $this->buildL10n(),
            $stubMapper,
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'any-uuid'));
    }//end testListReturnsEmptyWhenAppNotInstalled()

    public function testListReturnsEmptyWhenNoPageCarriesMarker(): void
    {
        $stubMapper = $this->createMock(\OCA\Collectives\Db\PageMapper::class);
        $stubMapper->method('getAll')->willReturn([
            $this->buildPage(1, 'page-without-marker'),
            $this->buildPage(2, 'another-untagged-page'),
        ]);

        $provider = $this->buildProviderWithStubMapper(
            $this->createMock(IDBConnection::class),
            $this->buildAppManager(installed: true),
            $this->buildL10n(),
            $stubMapper,
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'aaaa-bbbb-cccc'));
    }//end testListReturnsEmptyWhenNoPageCarriesMarker()

    public function testListSkipsSoftDeletedPagesEvenWhenMarkerMatches(): void
    {
        $marker        = '[or:obj-uuid]';
        $livePage      = $this->buildPage(10, 'live-'.$marker);
        $trashedPage   = $this->buildPage(11, 'trashed-'.$marker, trashTimestamp: 1700000000);

        $stubMapper = $this->createMock(\OCA\Collectives\Db\PageMapper::class);
        $stubMapper->method('getAll')->willReturn([$livePage, $trashedPage]);

        $provider = $this->buildProviderWithStubMapper(
            $this->createMock(IDBConnection::class),
            $this->buildAppManager(installed: true),
            $this->buildL10n(),
            $stubMapper,
        );

        $rows = $provider->list('reg', 'sch', 'obj-uuid');
        $this->assertCount(1, $rows);
        $this->assertSame('10', $rows[0]['id']);
    }//end testListSkipsSoftDeletedPagesEvenWhenMarkerMatches()

    public function testListReturnsEmptyWhenPageMapperUnreachable(): void
    {
        // resolvePageMapper() returns null — list() must degrade
        // gracefully (AD-23) rather than throw or call getAll() on null.
        $provider = $this->buildProviderWithStubMapper(
            $this->createMock(IDBConnection::class),
            $this->buildAppManager(installed: true),
            $this->buildL10n(),
            null,
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
    }//end testListReturnsEmptyWhenPageMapperUnreachable()

    public function testHealthReportsOkWhenAppInstalled(): void
    {
        $provider = new CollectivesProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(installed: true),
            l10n: $this->buildL10n(),
        );

        $health = $provider->health();
        $this->assertSame('ok', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertNull($health['message']);
    }//end testHealthReportsOkWhenAppInstalled()

    public function testHealthReportsUnavailableWhenAppMissing(): void
    {
        $provider = new CollectivesProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(installed: false),
            l10n: $this->buildL10n(),
        );

        $health = $provider->health();
        $this->assertSame('unavailable', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertSame('NC Collectives app is not installed', $health['message']);
    }//end testHealthReportsUnavailableWhenAppMissing()
}//end class
