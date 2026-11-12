<?php

/**
 * The Cospend leaf on an object: link rows for projects and bills, and the
 * marker fallback that lists marked projects with their latest bills through
 * Cospend's own mappers, degrading to nothing when a mapper, a query or a
 * table is missing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\CospendLink;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\CospendLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\Providers\CospendProvider;
use OCA\OpenRegister\Service\Integration\Providers\LeafProviderPlatform;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * CospendProvider.
 */
class CospendProviderBehaviourTest extends TestCase {

	private const PROJECT_MAPPER = 'OCA\\Cospend\\Db\\ProjectMapper';

	private const BILL_MAPPER = 'OCA\\Cospend\\Db\\BillMapper';

	/**
	 * Rows per table, or a Throwable that makes the query on it fail.
	 *
	 * @var array<string, list<array<string, mixed>>|\Throwable>
	 */
	private array $tables = [];

	/**
	 * Services the container resolves; a Throwable fails the lookup.
	 *
	 * @var array<string, mixed>
	 */
	private array $services = [];

	/**
	 * Queries run, as "table:first parameter".
	 *
	 * @var list<string>
	 */
	private array $queries = [];

	private ?int $maxResults = null;

	private function provider(bool $installed = true, array $links = []): CospendProvider {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('cospend')->willReturn($installed);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$mapper = $this->createMock(CospendLinkMapperInterface::class);
		$mapper->method('findByObjectUuid')->willReturn($links);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $name): mixed {
				$service = ($this->services[$name] ?? new RuntimeException('not registered'));
				if ($service instanceof \Throwable) {
					throw $service;
				}
				return $service;
			}
		);

		return new CospendProvider(new LeafProviderPlatform($this->db(), $apps, $l10n), $mapper, $container);
	}//end provider()

	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => addcslashes($value, '%_\\'));
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
		return $db;
	}//end db()

	private function queryBuilder(): IQueryBuilder {
		$state = ['table' => '', 'params' => []];
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'where', 'andWhere', 'orderBy'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}
		$qb->method('setMaxResults')->willReturnCallback(
			function (?int $max) use ($qb): IQueryBuilder {
				$this->maxResults = $max;
				return $qb;
			}
		);
		$qb->method('from')->willReturnCallback(
			function (string $table) use (&$state, $qb): IQueryBuilder {
				$state['table'] = $table;
				return $qb;
			}
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value) use (&$state): string {
				$state['params'][] = $value;
				return ':p';
			}
		);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$state): IResult {
				$this->queries[] = $state['table'].':'.$state['params'][0];
				$rows = ($this->tables[$state['table']] ?? []);
				if ($rows instanceof \Throwable) {
					throw $rows;
				}
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($rows);
				return $result;
			}
		);
		return $qb;
	}//end queryBuilder()

	private static function mapper(string $table): object {
		return new class ($table) {
			public function __construct(private string $table) {
			}

			public function getTableName(): string {
				return $this->table;
			}
		};
	}//end mapper()

	private function cospendInstalled(): void {
		$this->services = [
			self::PROJECT_MAPPER => self::mapper('cospend_projects'),
			self::BILL_MAPPER => self::mapper('cospend_bills'),
		];
	}//end cospendInstalled()

	public function testItDescribesItselfAndItsHealthFollowsTheCospendApp(): void {
		$provider = $this->provider();

		$this->assertSame(['cospend', 'Costs', 'CurrencyEur', 'workflow'], [$provider->getId(), $provider->getLabel(), $provider->getIcon(), $provider->getGroup()]);
		$this->assertSame('ok', $provider->health()['status']);
		$this->assertSame(
			['status' => 'unavailable', 'authStatus' => 'configured', 'message' => 'NC Cospend app is not installed'],
			$this->provider(false)->health()
		);
	}//end testItDescribesItselfAndItsHealthFollowsTheCospendApp()

	public function testLinkedProjectsAndBillsBecomeRows(): void {
		$project = new CospendLink();
		$project->setId(5);
		$project->setEntryType('project');
		$project->setProjectId('trip');
		$project->setName('Trip');
		$bill = new CospendLink();
		$bill->setId(6);
		$bill->setEntryType('bill');
		$bill->setProjectId('trip');
		$bill->setBillId(42);
		$bill->setName('Fuel');
		$bill->setAmount(12.5);
		$bill->setCurrency('EUR');
		$billWithoutId = new CospendLink();
		$billWithoutId->setId(7);
		$billWithoutId->setEntryType('bill');
		$billWithoutId->setProjectId('trip');
		$billWithoutId->setName('Unknown');

		$rows = $this->provider(true, [$project, $bill, $billWithoutId])->list(new ObjectRef(register: 'r', schema: 's', id: 'obj-1'));

		$this->assertSame(['trip', 'trip/42', 'trip'], array_column($rows, 'id'));
		$this->assertSame([5, 6, 7], array_column($rows, 'entryId'));
		$this->assertSame(['project', 'bill', 'bill'], array_column($rows, 'type'));
		$this->assertSame('/index.php/apps/cospend/p/trip', $rows[1]['url']);
		$this->assertSame([12.5, 'EUR'], [$rows[1]['amount'], $rows[1]['currency']]);
		$this->assertSame('Fuel', $rows[1]['title']);
		$this->assertSame([], $this->queries);
	}//end testLinkedProjectsAndBillsBecomeRows()

	public function testWithoutLinksMarkedProjectsAreListedEachFollowedByItsBills(): void {
		$this->cospendInstalled();
		$this->tables = [
			'cospend_projects' => [
				['id' => 'trip', 'name' => 'Trip [or:obj-1] ', 'user_id' => 'alice', 'currency_name' => 'EUR', 'last_changed' => '1700000000'],
				['id' => 'empty', 'name' => '[or:obj-1]'],
			],
			'cospend_bills' => [
				['id' => 42, 'what' => 'Fuel', 'amount' => '12.5', 'payer_id' => '3', 'timestamp' => 1700000100],
				['id' => 43, 'what' => '', 'amount' => null],
			],
		];

		$rows = $this->provider()->list(new ObjectRef(register: 'r', schema: 's', id: 'obj-1'));

		$this->assertSame(['trip', 'trip/42', 'trip/43', 'empty', 'empty/42', 'empty/43'], array_column($rows, 'id'));
		$this->assertSame(
			[
				'type' => 'project',
				'id' => 'trip',
				'title' => 'Trip',
				'description' => 'alice',
				'url' => '/index.php/apps/cospend/p/trip',
				'lastUpdated' => 1700000000,
				'data' => ['id' => 'trip', 'name' => 'Trip', 'userId' => 'alice', 'currencyName' => 'EUR', 'lastChanged' => 1700000000],
			],
			$rows[0]
		);
		$this->assertSame(
			[
				'type' => 'bill',
				'id' => 'trip/42',
				'title' => 'Fuel',
				'description' => gmdate('c', 1700000100),
				'url' => '/index.php/apps/cospend/p/trip',
				'lastUpdated' => 1700000100,
				'data' => ['id' => '42', 'projectId' => 'trip', 'what' => 'Fuel', 'amount' => 12.5, 'payerId' => 3, 'timestamp' => 1700000100, 'currencyName' => 'EUR'],
			],
			$rows[1]
		);
		$this->assertSame(['Trip', '', null, 0.0], [$rows[2]['title'], $rows[2]['description'], $rows[2]['lastUpdated'], $rows[2]['data']['amount']]);
		$this->assertSame(['', null, ''], [$rows[3]['title'], $rows[3]['lastUpdated'], $rows[3]['description']]);
		$this->assertSame(['cospend_projects:%[or:obj-1]%', 'cospend_bills:trip', 'cospend_bills:empty'], $this->queries);
		$this->assertSame(25, $this->maxResults);
	}//end testWithoutLinksMarkedProjectsAreListedEachFollowedByItsBills()

	public function testMarkedProjectsAreListedWithoutBillsWhenTheBillMapperOrItsTableIsMissing(): void {
		$this->services = [self::PROJECT_MAPPER => self::mapper('cospend_projects')];
		$this->tables = ['cospend_projects' => [['id' => 'trip', 'name' => 'Trip']]];

		$this->assertSame(['trip'], array_column($this->provider()->list(new ObjectRef(register: 'r', schema: 's', id: 'obj-1')), 'id'));

		$this->cospendInstalled();
		$this->tables['cospend_bills'] = new RuntimeException('no bills table');
		$this->assertSame(['trip'], array_column($this->provider()->list(new ObjectRef(register: 'r', schema: 's', id: 'obj-1')), 'id'));
	}//end testMarkedProjectsAreListedWithoutBillsWhenTheBillMapperOrItsTableIsMissing()

	public function testNoProjectMapperNoMarkedProjectOrAFailingProjectQueryListsNothing(): void {
		$this->assertSame([], $this->provider()->list(new ObjectRef(register: 'r', schema: 's', id: 'obj-1')));

		$this->services = [self::PROJECT_MAPPER => 'not-an-object'];
		$this->assertSame([], $this->provider()->list(new ObjectRef(register: 'r', schema: 's', id: 'obj-1')));

		$this->cospendInstalled();
		$this->assertSame([], $this->provider()->list(new ObjectRef(register: 'r', schema: 's', id: 'obj-1')));
		$this->assertSame(['cospend_projects:%[or:obj-1]%'], $this->queries);

		$this->tables['cospend_projects'] = new RuntimeException('no projects table');
		$this->assertSame([], $this->provider()->list(new ObjectRef(register: 'r', schema: 's', id: 'obj-1')));
	}//end testNoProjectMapperNoMarkedProjectOrAFailingProjectQueryListsNothing()

	public function testWithoutTheCospendAppNothingIsListed(): void {
		$this->cospendInstalled();
		$this->tables = ['cospend_projects' => [['id' => 'trip', 'name' => 'Trip']]];

		$this->assertSame([], $this->provider(false)->list(new ObjectRef(register: 'r', schema: 's', id: 'obj-1')));
		$this->assertSame([], $this->queries);
	}//end testWithoutTheCospendAppNothingIsListed()
}//end class
