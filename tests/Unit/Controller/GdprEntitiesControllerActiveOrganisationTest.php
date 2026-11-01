<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\GdprEntitiesController;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Decision\DecisionVisibility;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A non-admin who belongs to two organisations sees only the GDPR entities,
 * types, categories and counts of their active organisation.
 *
 * The database is a small in-memory table that applies the organisation
 * filter the controller binds, so the assertions are about which rows come
 * back, not about which query was built.
 *
 * @covers \OCA\OpenRegister\Controller\GdprEntitiesController
 * @uses \OCA\OpenRegister\Db\Organisation
 */
class GdprEntitiesControllerActiveOrganisationTest extends TestCase {
	/** @var list<array{id: int, organisation: string, type: string, category: string, value: string}> */
	private const ENTITIES = [
		['id' => 1, 'organisation' => 'org-active', 'type' => 'EMAIL', 'category' => 'pii', 'value' => 'a@active.nl'],
		['id' => 2, 'organisation' => 'org-active', 'type' => 'NAME', 'category' => 'pii', 'value' => 'Jan'],
		['id' => 3, 'organisation' => 'org-other', 'type' => 'BSN', 'category' => 'special', 'value' => '123456782'],
		['id' => 4, 'organisation' => 'org-other', 'type' => 'PHONE', 'category' => 'contact', 'value' => '0612345678'],
		['id' => 5, 'organisation' => 'org-other', 'type' => 'NAME', 'category' => 'pii', 'value' => 'Piet'],
		['id' => 6, 'organisation' => 'org-stranger', 'type' => 'IBAN', 'category' => 'financial', 'value' => 'NL00BANK'],
	];

	/** @var array<int, int> Relations per entity id. */
	private const RELATIONS = [1 => 2, 3 => 5, 6 => 1];

	public function testIndexListsOnlyTheActiveOrganisationsEntities(): void {
		$data = $this->controller(active: 'org-active')->index()->getData();

		$this->assertTrue($data['success']);
		$this->assertSame([1, 2], array_column($data['data'], 'id'));
		$this->assertSame(2, $data['count']);
		$this->assertSame([2, 0], array_column($data['data'], 'relationCount'));
	}

	public function testStatsCountOnlyTheActiveOrganisation(): void {
		$data = $this->controller(active: 'org-active')->getStats()->getData();

		$this->assertSame(
			[
				'totalEntities' => 2,
				'totalRelations' => 2,
				'byType' => ['EMAIL' => 1, 'NAME' => 1],
				'byCategory' => ['pii' => 2],
			],
			$data['data']
		);
	}

	public function testTypesAndCategoriesComeOnlyFromTheActiveOrganisation(): void {
		$controller = $this->controller(active: 'org-active');

		$this->assertSame(['EMAIL', 'NAME'], $controller->getTypes()->getData()['data']);
		$this->assertSame(['pii'], $controller->getCategories()->getData()['data']);
	}

	public function testSwitchingTheActiveOrganisationSwitchesTheView(): void {
		$controller = $this->controller(active: 'org-other');

		$this->assertSame([3, 4, 5], array_column($controller->index()->getData()['data'], 'id'));
		$this->assertSame(3, $controller->getStats()->getData()['data']['totalEntities']);
		$this->assertSame(5, $controller->getStats()->getData()['data']['totalRelations']);
	}

	public function testNoActiveOrganisationGivesAnEmptyPageAndZeroCounts(): void {
		$controller = $this->controller(active: null);

		$index = $controller->index()->getData();
		$this->assertSame([], $index['data']);
		$this->assertSame(0, $index['count']);
		$this->assertSame(
			['totalEntities' => 0, 'totalRelations' => 0, 'byType' => [], 'byCategory' => []],
			$controller->getStats()->getData()['data']
		);
		$this->assertSame([], $controller->getTypes()->getData()['data']);
		$this->assertSame([], $controller->getCategories()->getData()['data']);
	}

	public function testAnAdminStillSeesEveryOrganisation(): void {
		$controller = $this->controller(active: 'org-active', admin: true);

		$this->assertSame([1, 2, 3, 4, 5, 6], array_column($controller->index()->getData()['data'], 'id'));
		$this->assertSame(6, $controller->getStats()->getData()['data']['totalEntities']);
		$this->assertSame(8, $controller->getStats()->getData()['data']['totalRelations']);
	}

	/**
	 * Controller for `bob`, a member of org-active and org-other.
	 *
	 * The organisation service answers only for the session user, and the
	 * group manager says admin only for `bob` when $admin is set.
	 *
	 * @param string|null $active UUID of bob's active organisation, null for none.
	 * @param bool        $admin  Whether bob is an admin.
	 *
	 * @return GdprEntitiesController
	 */
	private function controller(?string $active, bool $admin = false): GdprEntitiesController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $admin && $uid === 'bob');

		$organisationService = $this->createMock(OrganisationService::class);
		$organisationService->method('getUserOrganisations')->willReturnCallback(function () use ($userSession): array {
			if ($userSession->getUser()?->getUID() !== 'bob') {
				return [];
			}

			return [$this->organisation('org-active'), $this->organisation('org-other')];
		});
		$organisationService->method('getActiveOrganisation')->willReturnCallback(function () use ($userSession, $active): ?Organisation {
			if ($active === null || $userSession->getUser()?->getUID() !== 'bob') {
				return null;
			}

			return $this->organisation($active);
		});

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $default);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->table());

		return new GdprEntitiesController(
			'openregister',
			$request,
			$this->createMock(GdprEntityMapper::class),
			$this->createMock(EntityRelationMapper::class),
			$db,
			$this->createMock(LoggerInterface::class),
			$userSession,
			$groupManager,
			$organisationService,
			$this->createMock(DecisionVisibility::class)
		);
	}

	private function organisation(string $uuid): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		return $organisation;
	}

	/**
	 * A query builder over ENTITIES and RELATIONS that honours the bound
	 * organisation filter and the shape of the controller's five queries.
	 *
	 * @return IQueryBuilder
	 */
	private function table(): IQueryBuilder {
		$state = ['orgs' => null, 'from' => null, 'select' => [], 'distinct' => null, 'groupBy' => null, 'alias' => false];
		$qb = $this->createMock(IQueryBuilder::class);

		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$count = $this->createMock(IQueryFunction::class);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($count);
		$qb->method('func')->willReturn($func);
		$qb->method('createFunction')->willReturn($count);
		$qb->method('getSQL')->willReturn('SQL');
		$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = IQueryBuilder::PARAM_STR) use (&$state) {
			if ($type === IQueryBuilder::PARAM_STR_ARRAY) {
				$state['orgs'] = $value;
			}

			return ':param';
		});

		foreach (['where', 'andWhere', 'innerJoin', 'orderBy', 'setMaxResults', 'setFirstResult'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('from')->willReturnCallback(function ($table) use ($qb, &$state) {
			$state['from'] = $table;
			return $qb;
		});
		$qb->method('select')->willReturnCallback(function (...$columns) use ($qb, &$state) {
			$state['select'] = $columns;
			return $qb;
		});
		$qb->method('selectDistinct')->willReturnCallback(function ($column) use ($qb, &$state) {
			$state['distinct'] = $column;
			return $qb;
		});
		$qb->method('selectAlias')->willReturnCallback(function () use ($qb, &$state) {
			$state['alias'] = true;
			return $qb;
		});
		$qb->method('groupBy')->willReturnCallback(function ($column) use ($qb, &$state) {
			$state['groupBy'] = $column;
			return $qb;
		});

		$qb->method('executeQuery')->willReturnCallback(function () use (&$state): IResult {
			return $this->rows($state);
		});

		return $qb;
	}

	/**
	 * Rows (or the single count) the query described by $state returns.
	 *
	 * @param array{orgs: mixed, from: mixed, select: array<int, mixed>, distinct: mixed, groupBy: mixed, alias: bool} $state The query.
	 *
	 * @return IResult
	 */
	private function rows(array $state): IResult {
		$entities = array_values(array_filter(
			self::ENTITIES,
			fn (array $row): bool => $state['orgs'] === null || in_array($row['organisation'], $state['orgs'], true)
		));

		$rows = [];
		$one = (string)count($entities);
		if ($state['from'] === 'openregister_entity_relations') {
			$ids = array_column($entities, 'id');
			$one = (string)array_sum(array_intersect_key(self::RELATIONS, array_flip($ids)));
		} elseif ($state['distinct'] !== null) {
			$values = array_values(array_unique(array_column($entities, $state['distinct'])));
			sort($values);
			$rows = array_map(fn (string $value): array => [$state['distinct'] => $value], $values);
		} elseif ($state['groupBy'] !== null) {
			foreach (array_count_values(array_column($entities, $state['groupBy'])) as $value => $total) {
				$rows[] = [$state['groupBy'] => $value, 'count' => $total];
			}
		} elseif ($state['alias'] === true) {
			$rows = array_map(
				fn (array $row): array => [
					'id' => $row['id'],
					'uuid' => 'u-' . $row['id'],
					'type' => $row['type'],
					'value' => $row['value'],
					'category' => $row['category'],
					'detected_at' => null,
					'updated_at' => null,
					'relation_count' => self::RELATIONS[$row['id']] ?? 0,
				],
				$entities
			);
		}

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		$result->method('fetchOne')->willReturn($one);
		$result->method('closeCursor')->willReturn(true);
		return $result;
	}
}
