<?php

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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the scoped reads of GdprEntitiesController: index(),
 * getStats(), getTypes() and getCategories().
 *
 * Every case records each query-builder call (with the tenant filter and its
 * bound organisation UUIDs), the logger messages and the response, and
 * compares the recording with fixtures/gdpr-entities-scope-golden.json.
 * The group manager answers "admin" only for the uid `root`, and the
 * organisation service answers only for the session user, so a forged
 * userId, requestedBy, owner or organisation request value cannot widen the
 * scope. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\GdprEntitiesController
 * @uses \OCA\OpenRegister\Db\Organisation
 */
class GdprEntitiesControllerScopeGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/gdpr-entities-scope-golden.json';

	private const FORGED = ['userId' => 'root', 'requestedBy' => 'root', 'owner' => 'root', 'organisation' => 'org-root'];

	/** @var list<mixed> */
	private array $calls = [];

	private int $qbCount = 0;

	private int $paramCount = 0;

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$filters = ['limit' => '5', 'offset' => '10', 'search' => 'jan', 'type' => 'EMAIL', 'category' => 'pii'];
		$cases = [];
		foreach (['index', 'getStats', 'getTypes', 'getCategories'] as $action) {
			$cases[$action . ' admin'] = [['action' => $action, 'user' => 'root', 'params' => []]];
			$cases[$action . ' admin with filters'] = [['action' => $action, 'user' => 'root', 'params' => $filters]];
			$cases[$action . ' member scoped to own organisations'] = [['action' => $action, 'user' => 'bob', 'params' => $filters]];
			$cases[$action . ' member with forged identity'] = [['action' => $action, 'user' => 'bob', 'params' => self::FORGED + $filters]];
			$cases[$action . ' member without organisations'] = [['action' => $action, 'user' => 'carol', 'params' => $filters]];
			$cases[$action . ' member without organisations, forged identity'] = [['action' => $action, 'user' => 'carol', 'params' => self::FORGED]];
			$cases[$action . ' member with only blank organisation'] = [['action' => $action, 'user' => 'dave', 'params' => []]];
			$cases[$action . ' anonymous'] = [['action' => $action, 'user' => null, 'params' => []]];
			$cases[$action . ' anonymous with forged identity'] = [['action' => $action, 'user' => null, 'params' => self::FORGED]];
			$cases[$action . ' query failure'] = [['action' => $action, 'user' => 'bob', 'params' => [], 'fail' => true]];
			$cases[$action . ' admin with empty tables'] = [['action' => $action, 'user' => 'root', 'params' => [], 'empty' => true]];
		}

		$cases['index default limit with numeric strings'] = [['action' => 'index', 'user' => 'bob', 'params' => ['limit' => '2abc', 'offset' => 'x']]];
		return $cases;
	}

	#[DataProvider('cases')]
	public function testScopedReadMatchesTheRecording(array $case): void {
		$this->calls = [];
		$this->qbCount = 0;
		$this->paramCount = 0;
		$params = $case['params'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(function (string $key, $default = null) use ($params) {
			$this->calls[] = ['request.getParam' => $key];
			return $params[$key] ?? $default;
		});

		$user = null;
		if ($case['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['user']);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(function (string $uid) {
			$this->calls[] = ['groupManager.isAdmin' => $uid];
			return $uid === 'root';
		});

		$orgService = $this->createMock(OrganisationService::class);
		$orgService->method('getUserOrganisations')->willReturnCallback(function () use ($userSession) {
			$uid = $userSession->getUser()?->getUID();
			$this->calls[] = ['organisationService.getUserOrganisations' => $uid];
			$map = ['root' => ['org-root'], 'bob' => ['org-b1', '', 'org-b2', 'org-b1'], 'carol' => [], 'dave' => ['']];
			return array_map(fn (string $uuid) => $this->organisation($uuid), $map[$uid] ?? []);
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder($case));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function ($message, array $context = []) {
			$this->calls[] = ['logger.error' => [$message, $context['error'] ?? null]];
		});

		$controller = new GdprEntitiesController(
			'openregister',
			$request,
			$this->createMock(GdprEntityMapper::class),
			$this->createMock(EntityRelationMapper::class),
			$db,
			$logger,
			$userSession,
			$groupManager,
			$orgService,
			$this->createMock(DecisionVisibility::class)
		);

		$response = $controller->{$case['action']}();
		$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()];
		$this->compare($this->dataName(), $actual);
	}

	private function organisation(string $uuid): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		return $organisation;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function queryBuilder(array $case): IQueryBuilder {
		$id = ++$this->qbCount;
		$name = 'qb' . $id;
		$state = ['from' => null, 'groupBy' => null, 'select' => [], 'distinct' => null];
		$qb = $this->createMock(IQueryBuilder::class);

		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'in', 'iLike'] as $op) {
			$expr->method($op)->willReturnCallback(fn ($x, $y) => $op . '(' . $this->text($x) . ', ' . $this->text($y) . ')');
		}

		$qb->method('expr')->willReturn($expr);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturnCallback(fn ($count = '', $alias = '') => $this->fn('COUNT(' . $this->text($count) . ')' . ($alias !== '' ? ' AS ' . $alias : '')));
		$qb->method('func')->willReturn($func);
		$qb->method('createFunction')->willReturnCallback(fn ($sql) => $this->fn('FN[' . $this->text($sql) . ']'));
		$qb->method('getSQL')->willReturn('SQL<' . $name . '>');
		$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = IQueryBuilder::PARAM_STR) {
			$this->paramCount++;
			$this->calls[] = ['param' => [':p' . $this->paramCount, $value, $type]];
			return ':p' . $this->paramCount;
		});

		$chain = ['select', 'selectDistinct', 'selectAlias', 'from', 'where', 'andWhere', 'innerJoin', 'orderBy', 'groupBy', 'setMaxResults', 'setFirstResult'];
		foreach ($chain as $method) {
			$qb->method($method)->willReturnCallback(function (...$args) use ($qb, $method, $name, &$state) {
				$this->calls[] = [$name . '.' . $method => array_map(fn ($a) => $this->text($a), $args)];
				if ($method === 'from') {
					$state['from'] = $args[0];
				}

				if ($method === 'groupBy') {
					$state['groupBy'] = $args[0];
				}

				if ($method === 'selectDistinct') {
					$state['distinct'] = $args[0];
				}

				if ($method === 'select') {
					$state['select'] = $args;
				}

				return $qb;
			});
		}

		$qb->method('executeQuery')->willReturnCallback(function () use ($name, &$state, $case) {
			$this->calls[] = [$name . '.executeQuery' => true];
			if (($case['fail'] ?? false) === true) {
				throw new RuntimeException('db down');
			}

			return $this->queryResult($state, ($case['empty'] ?? false) === true);
		});

		return $qb;
	}

	/**
	 * @param array{from: mixed, groupBy: mixed, select: array<int, mixed>, distinct: mixed} $state
	 */
	private function queryResult(array $state, bool $empty): IResult {
		$rows = [];
		$distinct = $state['distinct'];
		if ($empty === false && $distinct !== null) {
			$rows = [[$distinct => 'first'], [$distinct => 'second']];
		}

		$one = $empty ? '0' : ($state['from'] === 'openregister_entity_relations' ? '4' : '7');
		if ($empty === false && $state['groupBy'] === 'type') {
			$rows = [['type' => 'EMAIL', 'count' => '3'], ['type' => 'NAME', 'count' => 2]];
		} elseif ($empty === false && $state['groupBy'] === 'category') {
			$rows = [['category' => 'pii', 'count' => '5'], ['category' => 'special', 'count' => '1']];
		} elseif ($empty === false && $distinct === null && count($state['select']) > 1) {
			$rows = [
				['id' => '11', 'uuid' => 'u-11', 'type' => 'EMAIL', 'value' => 'a@b.nl', 'category' => 'pii', 'detected_at' => 'd1', 'updated_at' => 'u1', 'relation_count' => '2'],
				['id' => 12, 'uuid' => 'u-12', 'type' => 'NAME', 'value' => 'Jan', 'category' => 'pii', 'detected_at' => 'd2', 'updated_at' => null, 'relation_count' => null],
			];
		}

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		$result->method('fetchOne')->willReturn($one);
		$result->method('closeCursor')->willReturnCallback(function () {
			$this->calls[] = ['closeCursor' => true];
			return true;
		});
		return $result;
	}

	private function fn(string $text): IQueryFunction {
		$function = $this->createMock(IQueryFunction::class);
		$function->method('__toString')->willReturn($text);
		return $function;
	}

	private function text(mixed $value): mixed {
		if (is_object($value) === true && method_exists($value, '__toString') === true) {
			return (string)$value;
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $key, array $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			if (self::$recorded === null) {
				self::$recorded = [];
			}

			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(
				self::FIXTURE,
				json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
			);
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
