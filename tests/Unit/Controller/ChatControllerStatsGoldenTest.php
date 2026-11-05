<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ChatController;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for ChatController::getChatStats().
 *
 * Every case records each query-builder call (with the bound organisation
 * UUID and conversation ids), the logger messages and the response, and
 * compares the recording with fixtures/chat-stats-golden.json. The
 * organisation double answers with the session user's active organisation
 * only, so a forged userId, requestedBy, owner or organisation request value
 * cannot move the counts to another tenant. Set UPDATE_GOLDEN=1 to rewrite
 * the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\ChatController
 * @uses \OCA\OpenRegister\Db\Organisation
 */
class ChatControllerStatsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/chat-stats-golden.json';

	private const FORGED = ['userId' => 'mallory', 'requestedBy' => 'mallory', 'owner' => 'mallory', 'organisation' => 'org-mallory'];

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
		return [
			'organisation with conversations' => [['user' => 'alice']],
			'organisation with conversations, forged identity' => [['user' => 'alice', 'params' => self::FORGED]],
			'organisation without conversations' => [['user' => 'bob']],
			'no active organisation' => [['user' => 'carol']],
			'no active organisation, forged identity' => [['user' => 'carol', 'params' => self::FORGED]],
			'organisation without uuid' => [['user' => 'dave']],
			'query failure' => [['user' => 'alice', 'fail' => true]],
		];
	}

	#[DataProvider('cases')]
	public function testStatsMatchTheRecording(array $case): void {
		$this->calls = [];
		$this->qbCount = 0;
		$this->paramCount = 0;
		$params = $case['params'] ?? [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $params[$key] ?? $default);

		$user = $case['user'];
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getActiveOrganisation')->willReturnCallback(function () use ($user) {
			$this->calls[] = ['organisationService.getActiveOrganisation' => $user];
			$map = ['alice' => 'org-a', 'bob' => 'org-b', 'dave' => null];
			if (array_key_exists($user, $map) === false) {
				return null;
			}

			$organisation = new Organisation();
			$organisation->setUuid($map[$user]);
			return $organisation;
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder($case));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function ($message, array $context = []) {
			$this->calls[] = ['logger.error' => [$message, $context['error'] ?? null]];
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text) => 'T:' . $text);

		$controller = new ChatController(
			'openregister',
			$request,
			$this->createMock(ChatService::class),
			$this->createMock(ConversationMapper::class),
			$this->createMock(MessageMapper::class),
			$this->createMock(FeedbackMapper::class),
			$this->createMock(AgentMapper::class),
			$organisations,
			$db,
			$logger,
			$l10n,
			$user
		);

		$response = $controller->getChatStats();
		$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()];
		$this->compare($this->dataName(), $actual);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function queryBuilder(array $case): IQueryBuilder {
		$name = 'qb' . (++$this->qbCount);
		$state = ['from' => null, 'select' => null];
		$qb = $this->createMock(IQueryBuilder::class);

		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'in'] as $op) {
			$expr->method($op)->willReturnCallback(fn ($x, $y) => $op . '(' . $this->text($x) . ', ' . $this->text($y) . ')');
		}

		$qb->method('expr')->willReturn($expr);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturnCallback(fn ($count = '', $alias = '') => $this->fn('COUNT(' . $this->text($count) . ')' . ($alias !== '' ? ' AS ' . $alias : '')));
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = IQueryBuilder::PARAM_STR) {
			$this->paramCount++;
			$this->calls[] = ['param' => [':p' . $this->paramCount, $value, $type]];
			return ':p' . $this->paramCount;
		});

		foreach (['select', 'from', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (...$args) use ($qb, $method, $name, &$state) {
				$this->calls[] = [$name . '.' . $method => array_map(fn ($a) => $this->text($a), $args)];
				if ($method === 'from' || $method === 'select') {
					$state[$method] = $this->text($args[0]);
				}

				return $qb;
			});
		}

		$qb->method('executeQuery')->willReturnCallback(function () use ($name, &$state, $case) {
			$this->calls[] = [$name . '.executeQuery' => true];
			if (($case['fail'] ?? false) === true && $state['from'] === 'openregister_conversations') {
				throw new RuntimeException('db down');
			}

			return $this->queryResult($state, $case['user']);
		});

		return $qb;
	}

	/**
	 * @param array{from: mixed, select: mixed} $state
	 */
	private function queryResult(array $state, string $user): IResult {
		$rows = [];
		if ($state['select'] === 'id' && $user === 'alice') {
			$rows = [['id' => '7'], ['id' => 9]];
		}

		$counts = ['openregister_agents' => '3', 'openregister_conversations' => '2', 'openregister_messages' => '11'];
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		$result->method('fetchOne')->willReturn($counts[$state['from']] ?? '0');
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
