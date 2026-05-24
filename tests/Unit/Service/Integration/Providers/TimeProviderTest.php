<?php

/**
 * Unit tests for TimeProvider.
 *
 * Covers the contract surfaces required by the Bucket-A stub →
 * full-implementation completion (see
 * `openspec/changes/integration-time-tracker/tasks.md`):
 *
 *  - metadata matches the leaf spec (id / label / icon / group /
 *    requiredApp / storage strategy)
 *  - `isEnabled()` mirrors `IAppManager::isInstalled('timemanager')`
 *  - `list()` happy-path: linked client is found via the `[or:{uuid}]`
 *    `note` marker AND its tasks are surfaced alongside
 *  - `list()` absent-app: when NC Time Manager isn't installed,
 *    returns `[]` and never touches the ClientMapper container lookup
 *  - `list()` empty-result: app installed, marker matches no rows,
 *    returns `[]` cleanly
 *  - `list()` container-error: app installed but ClientMapper fails
 *    to resolve — returns `[]` without leaking the failure
 *  - `health()` reports `'unavailable'` with the documented missing-app
 *    message when NC Time Manager isn't installed
 *  - `health()` reports `'ok'` when the app is installed
 *
 * The Time Manager classes (`OCA\TimeManager\Db\ClientMapper`,
 * `OCA\TimeManager\Db\TaskMapper`) aren't on the test classpath when
 * the Time Manager app isn't installed, so the provider's container
 * lookup for those FQNs is exercised via a mocked `ContainerInterface`
 * that the tests inject through the optional `container` constructor
 * argument. The mocked mappers themselves are plain anonymous-class
 * stand-ins (no `extends QBMapper`) — the provider only calls
 * `getTableName()` on each, which is duck-typed by the implementation.
 *
 * Tagged `@group requires-app-timemanager` so CI runners without the
 * Time Manager app installed can skip the live-DB integration variant;
 * the unit tests in this file don't actually need the app, but the
 * tag is part of the established wave-1 pattern for time-tracker
 * (consistency with consumers that DO need a real install).
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
 * @spec openspec/changes/integration-time-tracker/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Service\Integration\Providers\TimeProvider;
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
 * Unit tests for TimeProvider.
 *
 * @group requires-app-timemanager
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class TimeProviderTest extends TestCase
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
        $provider = new TimeProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(['timemanager']),
            l10n: $this->buildL10n(),
        );

        $this->assertSame('time-tracker', $provider->getId());
        $this->assertSame('Time', $provider->getLabel());
        $this->assertSame('Clock', $provider->getIcon());
        $this->assertSame('workflow', $provider->getGroup());
        $this->assertSame('timemanager', $provider->getRequiredApp());
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
        $installed = new TimeProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(['timemanager']),
            l10n: $this->buildL10n(),
        );
        $missing   = new TimeProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager([]),
            l10n: $this->buildL10n(),
        );

        $this->assertTrue($installed->isEnabled());
        $this->assertFalse($missing->isEnabled());
    }//end testIsEnabledMirrorsAppManager()

    /**
     * `list()` returns `[]` cleanly when the Time Manager app isn't
     * installed and never touches the ClientMapper container lookup.
     *
     * @return void
     */
    public function testListReturnsEmptyWhenTimeManagerAppMissing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        // Strict expectation: container MUST NOT be queried when the
        // app isn't installed — early-return is the guarantee.
        $container->expects($this->never())->method('get');

        $provider = new TimeProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager([]),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
    }//end testListReturnsEmptyWhenTimeManagerAppMissing()

    /**
     * Happy-path: a client with the marker in its `note` is returned,
     * alongside its tasks, both in the leaf-row contract.
     *
     * @return void
     */
    public function testListSurfacesMatchedClientAndTasks(): void
    {
        $objectId = 'obj-uuid-abc';

        $clientRow = [
            'uuid'    => 'client-uuid-1',
            'name'    => 'Citizen Helpdesk',
            'note'    => 'Public-facing ticket-line [or:'.$objectId.']',
            'changed' => '2026-05-01T10:00:00Z',
        ];

        $taskRow = [
            'uuid'         => 'task-uuid-1',
            'name'         => 'Triage call',
            'note'         => 'Inbound from intake form [or:'.$objectId.']',
            'project_uuid' => 'proj-uuid-1',
            'changed'      => '2026-05-02T11:00:00Z',
        ];

        // Two consecutive marker queries — first for clients, then for
        // tasks. The DB builder helper returns a fresh result per call.
        $db = $this->buildDbReturningRowSequences(rowSequences: [[$clientRow], [$taskRow]]);

        $clientMapper = new class {
            public function getTableName(): string
            {
                return 'timemanager_client';
            }//end getTableName()
        };
        $taskMapper   = new class {
            public function getTableName(): string
            {
                return 'timemanager_task';
            }//end getTableName()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn(string $id): object => match ($id) {
                'OCA\\TimeManager\\Db\\ClientMapper' => $clientMapper,
                'OCA\\TimeManager\\Db\\TaskMapper'   => $taskMapper,
                default                              => throw new RuntimeException('unexpected service '.$id),
            }
        );

        $provider = new TimeProvider(
            db: $db,
            appManager: $this->buildAppManager(['timemanager']),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $rows = $provider->list('reg', 'sch', $objectId);

        $this->assertCount(2, $rows, 'one client row + one task row expected');

        // Row 0: the client.
        $this->assertSame('client', $rows[0]['type']);
        $this->assertSame('client-uuid-1', $rows[0]['id']);
        $this->assertSame('Citizen Helpdesk', $rows[0]['title']);
        $this->assertSame('Public-facing ticket-line', $rows[0]['description']);
        $this->assertSame('/index.php/apps/timemanager/clients/client-uuid-1', $rows[0]['url']);
        $this->assertSame('2026-05-01T10:00:00Z', $rows[0]['lastUpdated']);

        // Row 1: the task.
        $this->assertSame('task', $rows[1]['type']);
        $this->assertSame('task-uuid-1', $rows[1]['id']);
        $this->assertSame('Triage call', $rows[1]['title']);
        $this->assertSame('Inbound from intake form', $rows[1]['description']);
        $this->assertSame('/index.php/apps/timemanager/tasks/task-uuid-1', $rows[1]['url']);
        $this->assertSame('proj-uuid-1', $rows[1]['data']['projectUuid']);
    }//end testListSurfacesMatchedClientAndTasks()

    /**
     * Empty-result: app installed, marker matches no rows, returns
     * `[]` cleanly.
     *
     * @return void
     */
    public function testListReturnsEmptyWhenNoRowsMatchMarker(): void
    {
        // Both client and task queries return no rows.
        $db = $this->buildDbReturningRowSequences(rowSequences: [[], []]);

        $clientMapper = new class {
            public function getTableName(): string
            {
                return 'timemanager_client';
            }//end getTableName()
        };
        $taskMapper   = new class {
            public function getTableName(): string
            {
                return 'timemanager_task';
            }//end getTableName()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn(string $id): object => match ($id) {
                'OCA\\TimeManager\\Db\\ClientMapper' => $clientMapper,
                'OCA\\TimeManager\\Db\\TaskMapper'   => $taskMapper,
                default                              => throw new RuntimeException('unexpected service '.$id),
            }
        );

        $provider = new TimeProvider(
            db: $db,
            appManager: $this->buildAppManager(['timemanager']),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-zero'));
    }//end testListReturnsEmptyWhenNoRowsMatchMarker()

    /**
     * `list()` degrades gracefully when the ClientMapper itself fails
     * to resolve (Time Manager classpath not loaded, schema mismatch,
     * etc.) — a `NotFoundExceptionInterface` from the container
     * short-circuits to the empty-list contract.
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

        $provider = new TimeProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(['timemanager']),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
    }//end testListSwallowsContainerErrorsAndReturnsEmpty()

    /**
     * Task lookup failing (e.g. older Time Manager without the tasks
     * table) MUST NOT abort the client list — clients-only is a valid
     * intermediate state.
     *
     * @return void
     */
    public function testListSurfacesClientsWhenTaskMapperUnavailable(): void
    {
        $objectId  = 'obj-uuid-clients-only';
        $clientRow = [
            'uuid'    => 'client-uuid-c1',
            'name'    => 'Acme',
            'note'    => '[or:'.$objectId.']',
            'changed' => '2026-05-01T10:00:00Z',
        ];

        // First query (clients) returns the row; task query is never run
        // because the TaskMapper lookup throws.
        $db = $this->buildDbReturningRowSequences(rowSequences: [[$clientRow]]);

        $clientMapper = new class {
            public function getTableName(): string
            {
                return 'timemanager_client';
            }//end getTableName()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($clientMapper) {
                if ($id === 'OCA\\TimeManager\\Db\\ClientMapper') {
                    return $clientMapper;
                }

                if ($id === 'OCA\\TimeManager\\Db\\TaskMapper') {
                    throw new class extends RuntimeException implements NotFoundExceptionInterface {
                    };
                }

                throw new RuntimeException('unexpected service '.$id);
            }
        );

        $provider = new TimeProvider(
            db: $db,
            appManager: $this->buildAppManager(['timemanager']),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $rows = $provider->list('reg', 'sch', $objectId);

        $this->assertCount(1, $rows);
        $this->assertSame('client', $rows[0]['type']);
        $this->assertSame('client-uuid-c1', $rows[0]['id']);
    }//end testListSurfacesClientsWhenTaskMapperUnavailable()

    /**
     * `health()` reports `'unavailable'` with the documented
     * missing-app message when NC Time Manager isn't installed.
     *
     * @return void
     */
    public function testHealthReportsUnavailableWhenAppMissing(): void
    {
        $provider = new TimeProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager([]),
            l10n: $this->buildL10n(),
        );

        $health = $provider->health();
        $this->assertSame('unavailable', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertSame('NC Time Manager app is not installed', $health['message']);
    }//end testHealthReportsUnavailableWhenAppMissing()

    /**
     * `health()` reports `'ok'` when NC Time Manager is installed.
     *
     * @return void
     */
    public function testHealthReportsOkWhenAppInstalled(): void
    {
        $provider = new TimeProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(['timemanager']),
            l10n: $this->buildL10n(),
        );

        $health = $provider->health();
        $this->assertSame('ok', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertNull($health['message']);
    }//end testHealthReportsOkWhenAppInstalled()

    /**
     * Build an IDBConnection mock whose QueryBuilder yields the given
     * row sequences in order (one per `executeQuery()` call).
     *
     * Matches the shape TimeProvider expects: a chained `select → from
     * → where → orderBy → executeQuery → fetch` flow. The first
     * `executeQuery()` returns the result for the first sequence, the
     * second call returns the next one, and so on. Each sequence is
     * yielded element-by-element via `fetch()`; once exhausted, `fetch`
     * returns `false`.
     *
     * @param array<int,array<int,array<string,mixed>>> $rowSequences Sequences of rows
     *                                                                to return on
     *                                                                successive
     *                                                                `executeQuery()`
     *                                                                calls.
     *
     * @return IDBConnection
     */
    private function buildDbReturningRowSequences(array $rowSequences): IDBConnection
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

        $callIndex = 0;
        $qb->method('executeQuery')->willReturnCallback(
            function () use (&$callIndex, $rowSequences) {
                $sequence = $rowSequences[$callIndex] ?? [];
                $callIndex++;

                $result   = $this->createMock(\OCP\DB\IResult::class);
                $position = 0;
                $stream   = array_merge($sequence, [false]);
                $result->method('fetch')->willReturnCallback(
                    static function () use (&$position, $stream) {
                        $value = $stream[$position] ?? false;
                        $position++;
                        return $value;
                    }
                );
                return $result;
            }
        );

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $db->method('escapeLikeParameter')->willReturnArgument(0);

        return $db;
    }//end buildDbReturningRowSequences()
}//end class
