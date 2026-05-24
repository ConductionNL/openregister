<?php

/**
 * Unit tests for AnalyticsProvider.
 *
 * Covers the contract surfaces required by the Bucket-A stub →
 * full-implementation completion (see
 * `openspec/changes/integration-analytics/tasks.md`):
 *
 *  - metadata matches the leaf spec (id / label / icon / group /
 *    requiredApp / storage strategy)
 *  - `isEnabled()` mirrors `IAppManager::isInstalled('analytics')`
 *  - `list()` happy-path: a report whose `subheader` carries the
 *    `[or:{uuid}]` marker is resolved via `ReportService::read()` and
 *    surfaced as a normalised leaf row
 *  - `list()` absent-app: when NC Analytics isn't installed, returns
 *    `[]` and never touches the ReportService container lookup
 *  - `list()` empty-result: app installed, marker matches no reports,
 *    returns `[]` cleanly
 *  - `list()` container-error: a Throwable from the container short-
 *    circuits to the empty-list contract
 *  - `list()` per-user ACL: a marker-matched report that
 *    `ReportService::read()` cannot return for the current user is
 *    silently dropped (not surfaced)
 *  - `health()` reports the documented missing-app message when NC
 *    Analytics isn't installed
 *
 * The Analytics classes (`OCA\Analytics\Service\ReportService`) aren't
 * on the test classpath when the Analytics app isn't installed, so the
 * provider's container lookup for that FQN is exercised via a mocked
 * `ContainerInterface` that the tests inject through the optional
 * `container` constructor argument. The mocked `ReportService` itself
 * is a plain anonymous-class stand-in — the provider only calls
 * `read()` on it, which is duck-typed by the implementation.
 *
 * The class file is annotated `@group requires-app-analytics` so that
 * CI environments without the NC Analytics app installed (which is the
 * default for the OpenRegister test matrix) can opt out via
 * `--exclude-group requires-app-analytics`. All assertions in this
 * file run entirely against in-process mocks so the group filter is
 * advisory, not load-bearing.
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
 * @spec openspec/changes/integration-analytics/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Service\Integration\Providers\AnalyticsProvider;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Unit tests for AnalyticsProvider.
 *
 * @group requires-app-analytics
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class AnalyticsProviderTest extends TestCase
{
    /**
     * Build an IL10N mock that passes strings through.
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
     * Build an IAppManager mock that reports the given apps installed.
     *
     * @param array<int,string> $installed App ids to treat as installed.
     *
     * @return IAppManager
     */
    private function buildAppManager(array $installed=[]): IAppManager
    {
        $mock = $this->createMock(IAppManager::class);
        $mock->method('isInstalled')->willReturnCallback(
            static fn(string $appId): bool => in_array($appId, $installed, true)
        );
        return $mock;
    }//end buildAppManager()

    /**
     * Provider exposes the metadata declared in the leaf spec.
     *
     * @return void
     */
    public function testMetadataMatchesLeafSpec(): void
    {
        $provider = new AnalyticsProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(['analytics']),
            l10n: $this->buildL10n(),
        );

        $this->assertSame('analytics', $provider->getId());
        $this->assertSame('Analytics', $provider->getLabel());
        $this->assertSame('ChartBar', $provider->getIcon());
        $this->assertSame('workflow', $provider->getGroup());
        $this->assertSame('analytics', $provider->getRequiredApp());
        $this->assertSame('link-table', $provider->getStorageStrategy());
        $this->assertNull($provider->getOpenConnectorSource());
        $this->assertNull($provider->requiresPermission());
    }//end testMetadataMatchesLeafSpec()

    /**
     * `isEnabled()` mirrors the IAppManager check.
     *
     * @return void
     */
    public function testIsEnabledMirrorsAppManager(): void
    {
        $installed = new AnalyticsProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(['analytics']),
            l10n: $this->buildL10n(),
        );
        $missing   = new AnalyticsProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager([]),
            l10n: $this->buildL10n(),
        );

        $this->assertTrue($installed->isEnabled());
        $this->assertFalse($missing->isEnabled());
    }//end testIsEnabledMirrorsAppManager()

    /**
     * `list()` returns `[]` cleanly when the Analytics app isn't
     * installed and never touches the ReportService container lookup.
     *
     * @return void
     */
    public function testListReturnsEmptyWhenAnalyticsAppMissing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        // Strict expectation: container MUST NOT be queried when the
        // app isn't installed — early-return is the guarantee.
        $container->expects($this->never())->method('get');

        $provider = new AnalyticsProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager([]),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
    }//end testListReturnsEmptyWhenAnalyticsAppMissing()

    /**
     * Happy-path: a report whose subheader carries the marker is
     * resolved via `ReportService::read()` and surfaced as a leaf row.
     *
     * @return void
     */
    public function testListSurfacesMatchedReport(): void
    {
        $objectId = 'obj-uuid-abc';

        $candidateRow = [
            'id'        => 7,
            'subheader' => 'Cases per week [or:'.$objectId.']',
        ];

        $db = $this->buildDbReturningReportRows(rows: [$candidateRow]);

        // ReportService::read returns the full report row for the
        // current user; the provider should normalise it to the leaf
        // contract and strip the marker from the description.
        $reportService = new class {
            public function read(int $reportId, $replace=true): array
            {
                if ($reportId !== 7) {
                    return [];
                }

                return [
                    'id'        => 7,
                    'name'      => 'Cases per week',
                    'subheader' => 'Cases per week [or:obj-uuid-abc]',
                    'type'      => 1,
                    'dataset'   => 42,
                ];
            }//end read()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn(string $id): object => match ($id) {
                'OCA\\Analytics\\Service\\ReportService' => $reportService,
                default                                   => throw new RuntimeException('unexpected service '.$id),
            }
        );

        $provider = new AnalyticsProvider(
            db: $db,
            appManager: $this->buildAppManager(['analytics']),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $rows = $provider->list('reg', 'sch', $objectId);

        $this->assertCount(1, $rows);
        $this->assertSame('report', $rows[0]['type']);
        $this->assertSame('7', $rows[0]['id']);
        $this->assertSame('Cases per week', $rows[0]['title']);
        $this->assertSame('Cases per week', $rows[0]['description']);
        $this->assertSame('/index.php/apps/analytics/#/r/7', $rows[0]['url']);
        $this->assertIsArray($rows[0]['data']);
        $this->assertSame(42, $rows[0]['data']['dataset']);
    }//end testListSurfacesMatchedReport()

    /**
     * Empty-result: app installed, marker matches no reports, returns
     * `[]` cleanly without ever calling `ReportService::read()`.
     *
     * @return void
     */
    public function testListReturnsEmptyWhenNoReportsMatchMarker(): void
    {
        $db = $this->buildDbReturningReportRows(rows: []);

        // A ReportService that throws on `read()`: the provider MUST
        // short-circuit before calling read() when no candidate rows
        // match, so this throw should never fire.
        $reportService = new class {
            public function read(int $reportId, $replace=true): array
            {
                throw new RuntimeException('read() MUST NOT be called when no candidates match');
            }//end read()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn(string $id): object => match ($id) {
                'OCA\\Analytics\\Service\\ReportService' => $reportService,
                default                                   => throw new RuntimeException('unexpected service '.$id),
            }
        );

        $provider = new AnalyticsProvider(
            db: $db,
            appManager: $this->buildAppManager(['analytics']),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-zero'));
    }//end testListReturnsEmptyWhenNoReportsMatchMarker()

    /**
     * `list()` degrades gracefully when the ReportService itself fails
     * to resolve (Analytics classpath not loaded, schema mismatch,
     * etc.) — a `NotFoundExceptionInterface` from the container short-
     * circuits to the empty-list contract.
     *
     * @return void
     */
    public function testListSwallowsContainerErrorsAndReturnsEmpty(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(
            new class extends RuntimeException implements NotFoundExceptionInterface {
            }
        );

        $provider = new AnalyticsProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(['analytics']),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
    }//end testListSwallowsContainerErrorsAndReturnsEmpty()

    /**
     * Per-user ACL: a candidate row that `ReportService::read()`
     * cannot resolve for the current user (returns empty array) is
     * silently dropped — the registry tab MUST NOT leak hidden
     * reports.
     *
     * @return void
     */
    public function testListSkipsReportHiddenByPerUserAcl(): void
    {
        $objectId = 'obj-uuid-xyz';

        $db = $this->buildDbReturningReportRows(
            rows: [
                [
                    'id'        => 11,
                    'subheader' => 'Hidden [or:'.$objectId.']',
                ],
            ]
        );

        $reportService = new class {
            public function read(int $reportId, $replace=true): array
            {
                // Mimic Analytics' per-user filter: report 11 is not
                // visible to the current user.
                return [];
            }//end read()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn(string $id): object => match ($id) {
                'OCA\\Analytics\\Service\\ReportService' => $reportService,
                default                                   => throw new RuntimeException('unexpected service '.$id),
            }
        );

        $provider = new AnalyticsProvider(
            db: $db,
            appManager: $this->buildAppManager(['analytics']),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $this->assertSame([], $provider->list('reg', 'sch', $objectId));
    }//end testListSkipsReportHiddenByPerUserAcl()

    /**
     * `health()` reports `'unavailable'` with the documented missing-
     * app message when NC Analytics isn't installed.
     *
     * @return void
     */
    public function testHealthReportsUnavailableWhenAppMissing(): void
    {
        $provider = new AnalyticsProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager([]),
            l10n: $this->buildL10n(),
        );

        $health = $provider->health();
        $this->assertSame('unavailable', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertSame('NC Analytics app is not installed', $health['message']);
    }//end testHealthReportsUnavailableWhenAppMissing()

    /**
     * `health()` reports `'ok'` when NC Analytics is installed.
     *
     * @return void
     */
    public function testHealthReportsOkWhenAppInstalled(): void
    {
        $provider = new AnalyticsProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(['analytics']),
            l10n: $this->buildL10n(),
        );

        $health = $provider->health();
        $this->assertSame('ok', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertNull($health['message']);
    }//end testHealthReportsOkWhenAppInstalled()

    /**
     * Build an IDBConnection mock whose QueryBuilder yields the given
     * rows when executed.
     *
     * Matches the shape AnalyticsProvider expects: a chained `select →
     * from → where → orderBy → executeQuery → fetch` flow. The mock
     * doesn't assert call shape (covered implicitly by the row
     * contents), it just hands back the configured rows.
     *
     * @param array<int,array<string,mixed>> $rows Candidate rows to return.
     *
     * @return IDBConnection
     */
    private function buildDbReturningReportRows(array $rows): IDBConnection
    {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('iLike')->willReturn('iLike-clause');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);

        $result   = $this->createMock(\OCP\DB\IResult::class);
        $sequence = array_merge($rows, [false]);
        $position = 0;
        $result->method('fetch')->willReturnCallback(
            static function () use (&$position, $sequence) {
                $value = $sequence[$position] ?? false;
                $position++;
                return $value;
            }
        );
        $qb->method('executeQuery')->willReturn($result);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $db->method('escapeLikeParameter')->willReturnArgument(0);

        return $db;
    }//end buildDbReturningReportRows()
}//end class
