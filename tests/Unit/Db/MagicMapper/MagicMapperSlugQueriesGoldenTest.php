<?php

/**
 * Golden for the slug queries of a magic table.
 *
 * Records every builder call, the rows read and the answer of each public slug
 * method. Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\DateTimeNormalizerInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCA\OpenRegister\Service\SettingsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSlugBackfill
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSlugTable
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 */
class MagicMapperSlugQueriesGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/slug-queries-golden.json';

	/**
	 * Every call, rendered.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The rows of each query to come.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private array $results = [];

	/**
	 * Whether the table exists.
	 *
	 * @var bool
	 */
	private bool $tableExists = true;

	/**
	 * The cases: a call on the mapper and the rows of each of its queries.
	 *
	 * @return array<string, array{0: string, 1: array<int, mixed>, 2: array<int, array<int, array<string, mixed>>>}>
	 */
	private static function cases(): array {
		$many = array_map(static fn (int $index): string => "s{$index}", range(1, 1001));
		$backfill = [
			['_id' => 4, '_uuid' => 'u4', '_slug' => null, '_created' => '2026-01-02 00:00:00', 'title' => 'Vier'],
			['_id' => 7, '_uuid' => 'bad', '_slug' => '', '_created' => null],
			['_id' => 9, '_uuid' => 'u9', '_slug' => 'set', '_created' => null, 'title' => 'Negen'],
			['_id' => 11, '_uuid' => 'u11', '_slug' => '', '_created' => '2026-01-03 00:00:00', 'title' => 'Elf'],
		];

		return [
			'prefix' => ['findSlugsByPrefix', ['a%b_c', 5], [[['_slug' => 'a%b_c-1', '_uuid' => 'u1'], ['_slug' => 7, '_uuid' => 'u2']]]],
			'prefix default limit' => ['findSlugsByPrefix', ['x'], [[]]],
			'holders' => ['findSlugHolders', ['zaak'], [[['_uuid' => 'u1']]]],
			'holders but one' => ['findSlugHolders', ['zaak', 'u9'], [[]]],
			'state' => [
				'findSlugState',
				[['a', 'a', '', null, 'b'], ['u1', '', null, 'u2', 'u3']],
				[
					[['_uuid' => 'u1', '_slug' => 'a'], ['_uuid' => 'u7', '_slug' => 'a']],
					[['_uuid' => 'u1', '_slug' => 'a'], ['_uuid' => 'u2', '_slug' => ''], ['_uuid' => 'u3', '_slug' => null]],
				],
			],
			'state chunks' => ['findSlugState', [$many, []], [[['_uuid' => 'u1', '_slug' => 's1']], [['_uuid' => 'u2', '_slug' => 's1001']]]],
			'state of nothing' => ['findSlugState', [[], []], []],
			'no duplicates' => ['findDuplicateSlugs', [], [[]]],
			'duplicates' => [
				'findDuplicateSlugs',
				[],
				[
					[['slug_key' => 'hello'], ['slug_key' => 'twee']],
					[['_uuid' => 'u1', '_slug' => 'Hello'], ['_uuid' => 'u2', '_slug' => 'hello'], ['_uuid' => 'u3', '_slug' => 'TWEE']],
				],
			],
			'all' => ['findAllSlugs', [], [[['_uuid' => 'u1', '_slug' => 'a'], ['_uuid' => 'u2', '_slug' => 'a'], ['_uuid' => 'u3', '_slug' => 'b']]]],
			'backfill' => ['findSlugBackfillPage', [3, 50], [$backfill]],
			'backfill of nothing' => ['findSlugBackfillPage', [12, 50], [[]]],
			'write' => ['updateSlugColumn', ['u1', 'nieuw'], []],
		];
	}//end cases()

	public function testSlugQueriesMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$method, $args, $results]) {
			$actual[$name] = $this->outcome(method: $method, args: $args, results: $results);
		}

		foreach (self::cases() as $name => [$method, $args]) {
			if ($method !== 'updateSlugColumn') {
				$this->tableExists = false;
				$actual["{$name}, no table"] = $this->outcome(method: $method, args: $args, results: []);
				$this->tableExists = true;
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testSlugQueriesMatchGolden()

	/**
	 * Run one case.
	 *
	 * @param string                                       $method  The method.
	 * @param array<int, mixed>                            $args    Its arguments after the register and schema.
	 * @param array<int, array<int, array<string, mixed>>> $results The rows of each query.
	 *
	 * @return array{calls: array<int, string>, answer: mixed} The outcome.
	 */
	private function outcome(string $method, array $args, array $results): array {
		$this->calls = [];
		$this->results = $results;
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);

		$answer = $this->mapper()->{$method}($register, $schema, ...$args);

		return ['calls' => $this->calls, 'answer' => $answer];
	}//end outcome()

	/**
	 * The mapper on a recording connection.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function mapper(): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => addcslashes($value, '\\%_'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->calls[] = 'warning ' . $message . ' ' . json_encode($context);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$services = [
			DateTimeNormalizerInterface::class => $this->createMock(DateTimeNormalizer::class),
			ConditionMatcher::class => $this->createMock(ConditionMatcher::class),
			SchemaTypeConverterInterface::class => $this->createMock(SchemaTypeConverter::class),
		];
		$container->method('get')->willReturnCallback(static fn (string $id) => ($services[$id] ?? null));

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs(
				[
					$db,
					$this->createMock(SchemaMapper::class),
					$this->createMock(RegisterMapper::class),
					$this->createMock(IConfig::class),
					$this->createMock(IEventDispatcher::class),
					$this->createMock(IUserSession::class),
					$this->createMock(IGroupManager::class),
					$this->createMock(IUserManager::class),
					$this->createMock(IAppConfig::class),
					$logger,
					$this->createMock(SettingsService::class),
					$container,
				]
			)
			->onlyMethods(['existsTableForRegisterSchema', 'getTableNameForRegisterSchema', 'convertRowToObjectEntity'])
			->getMock();
		$mapper->method('existsTableForRegisterSchema')->willReturnCallback(fn (): bool => $this->tableExists);
		$mapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_2');
		$mapper->method('convertRowToObjectEntity')->willReturnCallback(
			static function (array $row): ?ObjectEntity {
				if ($row['_uuid'] === 'bad') {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setObject(['title' => $row['title']]);
				return $entity;
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * A builder that records every call.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$this->calls[] = 'builder';
		foreach (['select', 'selectAlias', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'setMaxResults', 'groupBy', 'having', 'update', 'set'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($method, $qb) {
					$this->calls[] = $method . ' ' . json_encode(array_map(static fn ($arg) => $arg instanceof ICompositeExpression ? 'composite' : (string) $arg, $args));
					return $qb;
				}
			);
		}

		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value, $type = null): string => 'param(' . json_encode($value) . ', ' . json_encode($type) . ')'
		);
		$qb->method('expr')->willReturn($this->expressions());
		$qb->method('func')->willReturn($this->functions());
		$qb->method('executeQuery')->willReturnCallback(fn (): IResult => $this->nextResult());
		$qb->method('executeStatement')->willReturnCallback(
			function (): int {
				$this->calls[] = 'executeStatement';
				return 1;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * Expressions rendered as text.
	 *
	 * @return IExpressionBuilder The builder.
	 */
	private function expressions(): IExpressionBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'gt', 'like', 'in', 'isNull', 'isNotNull'] as $method) {
			$expr->method($method)->willReturnCallback(
				static fn (...$args): string => $method . '(' . implode(', ', array_map(static fn ($arg) => (string) $arg, $args)) . ')'
			);
		}

		$expr->method('orX')->willReturnCallback(
			function (...$args): ICompositeExpression {
				$this->calls[] = 'orX ' . json_encode($args);
				return $this->createMock(ICompositeExpression::class);
			}
		);

		return $expr;
	}//end expressions()

	/**
	 * Functions rendered as text.
	 *
	 * @return IFunctionBuilder The builder.
	 */
	private function functions(): IFunctionBuilder {
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('lower')->willReturnCallback(fn ($value) => $this->rendered(call: 'lower(' . (string) $value . ')'));

		return $func;
	}//end functions()

	/**
	 * A query function that reads as the text of the call.
	 *
	 * @param string $call The text.
	 *
	 * @return mixed The function.
	 */
	private function rendered(string $call): mixed {
		$function = $this->createMock(IQueryFunction::class);
		$function->method('__toString')->willReturn($call);

		return $function;
	}//end rendered()

	/**
	 * The next query's result.
	 *
	 * @return IResult The result.
	 */
	private function nextResult(): IResult {
		$this->calls[] = 'executeQuery';
		$rows = (array_shift($this->results) ?? []);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(
			function () use (&$rows) {
				return (array_shift($rows) ?? false);
			}
		);
		$result->method('closeCursor')->willReturnCallback(
			function (): bool {
				$this->calls[] = 'closeCursor';
				return true;
			}
		);

		return $result;
	}//end nextResult()
}//end class
