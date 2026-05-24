<?php

/**
 * Unit tests for FormsProvider.
 *
 * Covers the contract surfaces required by the Bucket-A stub →
 * full-implementation completion (see
 * `openspec/changes/integration-forms/tasks.md`):
 *
 *  - metadata matches the leaf spec (id / label / icon / group /
 *    requiredApp / storage strategy)
 *  - `isEnabled()` mirrors `IAppManager::isInstalled('forms')`
 *  - `list()` happy-path: linked form is found via the `[or:{uuid}]`
 *    description marker AND its submissions are surfaced alongside
 *  - `list()` absent-app: when NC Forms isn't installed, returns `[]`
 *    and never touches the FormMapper container lookup
 *  - `list()` empty-result: app installed, marker matches no forms,
 *    returns `[]` cleanly
 *  - `health()` reports `'unavailable'` with the documented
 *    missing-app message when NC Forms isn't installed
 *
 * The Forms classes (`OCA\Forms\Db\FormMapper`,
 * `OCA\Forms\Db\SubmissionMapper`) aren't on the test classpath when
 * the Forms app isn't installed, so the provider's container lookup
 * for those FQNs is exercised via a mocked `ContainerInterface` that
 * the tests inject through the optional `container` constructor
 * argument. The mocked mappers themselves are plain anonymous-class
 * stand-ins (no `extends QBMapper`) — the provider only calls
 * `getTableName()` on the FormMapper and `findByForm()` on the
 * SubmissionMapper, both of which are duck-typed by the
 * implementation.
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
 * @spec openspec/changes/integration-forms/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Service\Integration\Providers\FormsProvider;
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
 * Unit tests for FormsProvider.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class FormsProviderTest extends TestCase
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
        $provider = new FormsProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(['forms']),
            l10n: $this->buildL10n(),
        );

        $this->assertSame('forms', $provider->getId());
        $this->assertSame('Forms', $provider->getLabel());
        $this->assertSame('ClipboardText', $provider->getIcon());
        $this->assertSame('workflow', $provider->getGroup());
        $this->assertSame('forms', $provider->getRequiredApp());
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
        $installed = new FormsProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(['forms']),
            l10n: $this->buildL10n(),
        );
        $missing   = new FormsProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager([]),
            l10n: $this->buildL10n(),
        );

        $this->assertTrue($installed->isEnabled());
        $this->assertFalse($missing->isEnabled());
    }//end testIsEnabledMirrorsAppManager()

    /**
     * `list()` returns `[]` cleanly when the Forms app isn't installed
     * and never touches the FormMapper container lookup.
     *
     * @return void
     */
    public function testListReturnsEmptyWhenFormsAppMissing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        // Strict expectation: container MUST NOT be queried when the
        // app isn't installed — early-return is the guarantee.
        $container->expects($this->never())->method('get');

        $provider = new FormsProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager([]),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
    }//end testListReturnsEmptyWhenFormsAppMissing()

    /**
     * Happy-path: a form with the marker in its description is
     * returned, alongside its submissions, both in the leaf-row
     * contract.
     *
     * @return void
     */
    public function testListSurfacesMatchedFormAndSubmissions(): void
    {
        $objectId = 'obj-uuid-abc';

        $formRow = [
            'id'           => 7,
            'hash'         => 'h7',
            'title'        => 'Citizen feedback',
            'description'  => 'Tell us how your case went [or:'.$objectId.']',
            'last_updated' => 1700000000,
        ];

        $db = $this->buildDbReturningFormRows(rows: [$formRow]);

        $formMapper       = new class {
            public function getTableName(): string
            {
                return 'forms_v2_forms';
            }//end getTableName()
        };
        $submissionMapper = new class {
            public function findByForm(
                int $formId,
                ?string $userId=null,
                ?string $searchString=null,
                ?int $limit=null,
                int $offset=0
            ): array {
                if ($formId !== 7) {
                    return [];
                }

                // Mix shapes the provider must handle: one assoc array,
                // one object-with-getters.
                $entityLike = new class {
                    public function getId(): int
                    {
                        return 101;
                    }//end getId()

                    public function getUserId(): string
                    {
                        return 'alice';
                    }//end getUserId()

                    public function getTimestamp(): int
                    {
                        return 1700000500;
                    }//end getTimestamp()
                };
                return [
                    ['id' => 100, 'userId' => 'bob', 'timestamp' => 1700000400],
                    $entityLike,
                ];
            }//end findByForm()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn(string $id): object => match ($id) {
                'OCA\\Forms\\Db\\FormMapper'       => $formMapper,
                'OCA\\Forms\\Db\\SubmissionMapper' => $submissionMapper,
                default                            => throw new RuntimeException('unexpected service '.$id),
            }
        );

        $provider = new FormsProvider(
            db: $db,
            appManager: $this->buildAppManager(['forms']),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $rows = $provider->list('reg', 'sch', $objectId);

        $this->assertCount(3, $rows, 'one form row + two submissions expected');

        // Row 0: the form.
        $this->assertSame('form', $rows[0]['type']);
        $this->assertSame('7', $rows[0]['id']);
        $this->assertSame('Citizen feedback', $rows[0]['title']);
        $this->assertSame('Tell us how your case went', $rows[0]['description']);
        $this->assertSame('/index.php/apps/forms/h7', $rows[0]['url']);
        $this->assertSame(1700000000, $rows[0]['lastUpdated']);

        // Rows 1 & 2: submissions, newest-first per SubmissionMapper.
        $this->assertSame('submission', $rows[1]['type']);
        $this->assertSame('7/100', $rows[1]['id']);
        $this->assertSame('bob', $rows[1]['description']);
        $this->assertStringStartsWith('Citizen feedback', $rows[1]['title']);
        $this->assertSame('/index.php/apps/forms/h7/results', $rows[1]['url']);
        $this->assertSame(1700000400, $rows[1]['lastUpdated']);

        $this->assertSame('submission', $rows[2]['type']);
        $this->assertSame('7/101', $rows[2]['id']);
        $this->assertSame('alice', $rows[2]['description']);
    }//end testListSurfacesMatchedFormAndSubmissions()

    /**
     * Empty-result: app installed, marker matches no forms, returns
     * `[]` cleanly (no submission lookup, no leaf rows).
     *
     * @return void
     */
    public function testListReturnsEmptyWhenNoFormsMatchMarker(): void
    {
        $db = $this->buildDbReturningFormRows(rows: []);

        $formMapper       = new class {
            public function getTableName(): string
            {
                return 'forms_v2_forms';
            }//end getTableName()
        };
        $submissionMapper = $this->createMock(\stdClass::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn(string $id): object => match ($id) {
                'OCA\\Forms\\Db\\FormMapper'       => $formMapper,
                'OCA\\Forms\\Db\\SubmissionMapper' => $submissionMapper,
                default                            => throw new RuntimeException('unexpected service '.$id),
            }
        );

        $provider = new FormsProvider(
            db: $db,
            appManager: $this->buildAppManager(['forms']),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-zero'));
    }//end testListReturnsEmptyWhenNoFormsMatchMarker()

    /**
     * `list()` degrades gracefully when the FormMapper itself fails to
     * resolve (Forms classpath not loaded, schema mismatch, etc.) —
     * a `NotFoundExceptionInterface` from the container short-circuits
     * to the empty-list contract.
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

        $provider = new FormsProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(['forms']),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
    }//end testListSwallowsContainerErrorsAndReturnsEmpty()

    /**
     * `health()` reports `'unavailable'` with the documented missing-app
     * message when NC Forms isn't installed.
     *
     * @return void
     */
    public function testHealthReportsUnavailableWhenAppMissing(): void
    {
        $provider = new FormsProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager([]),
            l10n: $this->buildL10n(),
        );

        $health = $provider->health();
        $this->assertSame('unavailable', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertSame('NC Forms app is not installed', $health['message']);
    }//end testHealthReportsUnavailableWhenAppMissing()

    /**
     * `health()` reports `'ok'` when NC Forms is installed.
     *
     * @return void
     */
    public function testHealthReportsOkWhenAppInstalled(): void
    {
        $provider = new FormsProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(['forms']),
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
     * Matches the shape FormsProvider expects: a chained `select →
     * from → where → orderBy → executeQuery → fetch` flow. The mock
     * doesn't assert call shape (covered implicitly by the row
     * contents), it just hands back the configured rows.
     *
     * @param array<int,array<string,mixed>> $rows Form rows to return.
     *
     * @return IDBConnection
     */
    private function buildDbReturningFormRows(array $rows): IDBConnection
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
    }//end buildDbReturningFormRows()
}//end class
