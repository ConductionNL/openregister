<?php

/**
 * Unit tests for AnalyticsSeriesService — page-level pre-computed chart
 * series register / fetch.
 *
 * Covers the contract required by the integration-leaf-foundation change:
 *   - register(): persists labels + datasets under a stable key, records
 *     the creator, declares the matching page widget on the registry,
 *     upserts on re-register; rejects invalid visibility / chart-type /
 *     anonymous caller.
 *   - fetch(): returns the render contract RBAC-scoped — public series
 *     readable by anyone; private/group only by creator or admin;
 *     unknown / disallowed → null (no oracle).
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
 * @spec openspec/changes/integration-leaf-foundation-shares-analytics/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use InvalidArgumentException;
use OCA\OpenRegister\Db\AnalyticsSeries;
use OCA\OpenRegister\Db\AnalyticsSeriesMapper;
use OCA\OpenRegister\Service\AnalyticsSeriesService;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AnalyticsSeriesService.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AnalyticsSeriesServiceTest extends TestCase
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
     * register() persists the series, records the creator and declares
     * the page widget on the registry.
     *
     * @return void
     */
    public function testRegisterPersistsAndDeclaresWidget(): void
    {
        $mapper = $this->createMock(AnalyticsSeriesMapper::class);
        $mapper->method('findByKey')->willReturn(null);
        $mapper->expects($this->once())->method('insert')->willReturnCallback(
            static function (AnalyticsSeries $s): AnalyticsSeries {
                $s->setId(1);
                return $s;
            }
        );

        $registry = $this->buildRegistry();

        $service = new AnalyticsSeriesService(
            mapper: $mapper,
            registry: $registry,
            userSession: $this->buildUserSession('alice'),
            groupManager: $this->buildGroupManager(false),
        );

        $result = $service->register(
            seriesKey: 'sla-breaches',
            labels: ['Wk1', 'Wk2'],
            datasets: [['label' => 'Breaches', 'data' => [3, 5]]],
            title: 'SLA breaches',
            chartType: 'bar',
            visibility: 'group',
            registerId: 2,
            schemaId: 4
        );

        $this->assertSame('sla-breaches', $result['seriesKey']);
        $this->assertSame(['Wk1', 'Wk2'], $result['labels']);
        $this->assertSame([['label' => 'Breaches', 'data' => [3, 5]]], $result['datasets']);
        $this->assertSame('alice', $result['createdBy']);

        $widget = $registry->getPageWidget('analytics-series:sla-breaches');
        $this->assertNotNull($widget, 'page widget must be declared on the registry');
        $this->assertSame('chart', $widget['type']);
        $this->assertSame('analytics-series', $widget['providerId']);
        $this->assertSame('sla-breaches', $widget['config']['seriesKey']);
    }//end testRegisterPersistsAndDeclaresWidget()


    /**
     * register() upserts an existing key (update, not duplicate insert).
     *
     * @return void
     */
    public function testRegisterUpsertsExisting(): void
    {
        $existing = new AnalyticsSeries();
        $existing->setId(9);
        $existing->setSeriesKey('sla-breaches');
        $existing->setCreatedBy('bob');

        $mapper = $this->createMock(AnalyticsSeriesMapper::class);
        $mapper->method('findByKey')->willReturn($existing);
        $mapper->expects($this->never())->method('insert');
        $mapper->expects($this->once())->method('update')->willReturnCallback(
            static fn (AnalyticsSeries $s): AnalyticsSeries => $s
        );

        $service = new AnalyticsSeriesService(
            mapper: $mapper,
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('alice'),
            groupManager: $this->buildGroupManager(false),
        );

        $result = $service->register(
            seriesKey: 'sla-breaches',
            labels: ['Wk1'],
            datasets: [['label' => 'x', 'data' => [1]]],
        );

        // Creator is preserved from the existing row (not overwritten).
        $this->assertSame('bob', $result['createdBy']);
    }//end testRegisterUpsertsExisting()


    /**
     * register() rejects an invalid visibility.
     *
     * @return void
     */
    public function testRegisterRejectsInvalidVisibility(): void
    {
        $service = new AnalyticsSeriesService(
            mapper: $this->createMock(AnalyticsSeriesMapper::class),
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('alice'),
            groupManager: $this->buildGroupManager(false),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->register(seriesKey: 'k', labels: [], datasets: [], visibility: 'world');
    }//end testRegisterRejectsInvalidVisibility()


    /**
     * register() rejects an invalid chart type.
     *
     * @return void
     */
    public function testRegisterRejectsInvalidChartType(): void
    {
        $service = new AnalyticsSeriesService(
            mapper: $this->createMock(AnalyticsSeriesMapper::class),
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('alice'),
            groupManager: $this->buildGroupManager(false),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->register(seriesKey: 'k', labels: [], datasets: [], chartType: 'sankey');
    }//end testRegisterRejectsInvalidChartType()


    /**
     * register() rejects an anonymous caller.
     *
     * @return void
     */
    public function testRegisterRejectsAnonymous(): void
    {
        $service = new AnalyticsSeriesService(
            mapper: $this->createMock(AnalyticsSeriesMapper::class),
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession(null),
            groupManager: $this->buildGroupManager(false),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->register(seriesKey: 'k', labels: [], datasets: []);
    }//end testRegisterRejectsAnonymous()


    /**
     * fetch() returns a public series to anyone (anonymous included).
     *
     * @return void
     */
    public function testFetchPublicSeriesReadableByAnyone(): void
    {
        $series = new AnalyticsSeries();
        $series->setSeriesKey('pub');
        $series->setVisibility('public');
        $series->setCreatedBy('bob');
        $series->setLabels(json_encode(['A']));
        $series->setDatasets(json_encode([['data' => [1]]]));

        $mapper = $this->createMock(AnalyticsSeriesMapper::class);
        $mapper->method('findByKey')->willReturn($series);

        $service = new AnalyticsSeriesService(
            mapper: $mapper,
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession(null),
            groupManager: $this->buildGroupManager(false),
        );

        $result = $service->fetch('pub');
        $this->assertNotNull($result);
        $this->assertSame(['A'], $result['labels']);
    }//end testFetchPublicSeriesReadableByAnyone()


    /**
     * fetch() denies a private series to a non-creator non-admin (null,
     * no oracle).
     *
     * @return void
     */
    public function testFetchPrivateSeriesDeniedToOther(): void
    {
        $series = new AnalyticsSeries();
        $series->setSeriesKey('priv');
        $series->setVisibility('private');
        $series->setCreatedBy('bob');

        $mapper = $this->createMock(AnalyticsSeriesMapper::class);
        $mapper->method('findByKey')->willReturn($series);

        $service = new AnalyticsSeriesService(
            mapper: $mapper,
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('mallory'),
            groupManager: $this->buildGroupManager(false),
        );

        $this->assertNull($service->fetch('priv'));
    }//end testFetchPrivateSeriesDeniedToOther()


    /**
     * fetch() allows the creator to read their own private series.
     *
     * @return void
     */
    public function testFetchPrivateSeriesAllowedToCreator(): void
    {
        $series = new AnalyticsSeries();
        $series->setSeriesKey('priv');
        $series->setVisibility('private');
        $series->setCreatedBy('bob');
        $series->setLabels(json_encode([]));
        $series->setDatasets(json_encode([]));

        $mapper = $this->createMock(AnalyticsSeriesMapper::class);
        $mapper->method('findByKey')->willReturn($series);

        $service = new AnalyticsSeriesService(
            mapper: $mapper,
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('bob'),
            groupManager: $this->buildGroupManager(false),
        );

        $this->assertNotNull($service->fetch('priv'));
    }//end testFetchPrivateSeriesAllowedToCreator()


    /**
     * fetch() allows an admin to read a private series.
     *
     * @return void
     */
    public function testFetchPrivateSeriesAllowedToAdmin(): void
    {
        $series = new AnalyticsSeries();
        $series->setSeriesKey('priv');
        $series->setVisibility('private');
        $series->setCreatedBy('bob');
        $series->setLabels(json_encode([]));
        $series->setDatasets(json_encode([]));

        $mapper = $this->createMock(AnalyticsSeriesMapper::class);
        $mapper->method('findByKey')->willReturn($series);

        $service = new AnalyticsSeriesService(
            mapper: $mapper,
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('root'),
            groupManager: $this->buildGroupManager(true),
        );

        $this->assertNotNull($service->fetch('priv'));
    }//end testFetchPrivateSeriesAllowedToAdmin()


    /**
     * fetch() returns null for an unknown key.
     *
     * @return void
     */
    public function testFetchUnknownReturnsNull(): void
    {
        $mapper = $this->createMock(AnalyticsSeriesMapper::class);
        $mapper->method('findByKey')->willReturn(null);

        $service = new AnalyticsSeriesService(
            mapper: $mapper,
            registry: $this->buildRegistry(),
            userSession: $this->buildUserSession('alice'),
            groupManager: $this->buildGroupManager(false),
        );

        $this->assertNull($service->fetch('nope'));
    }//end testFetchUnknownReturnsNull()
}//end class
