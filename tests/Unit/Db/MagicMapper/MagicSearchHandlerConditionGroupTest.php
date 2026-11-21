<?php

/**
 * The `_where` condition tree on both filter paths of MagicSearchHandler:
 * each leaf builds through the flat filter's own condition code, groups wrap
 * them in AND or OR, the whole tree is one expression, and a leaf the schema
 * cannot answer is refused with its node path.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\ConditionSqlRun;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\TableFilterRun;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ConditionGroupException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 */
class MagicSearchHandlerConditionGroupTest extends TestCase {

	private const PROPERTIES = [
		'place'    => ['type' => 'string'],
		'industry' => ['type' => 'string'],
		'secret'   => ['type' => 'object'],
		'sealed'   => ['type' => 'string', 'x-openregister-encrypted' => true],
	];

	private const TREE = [
		'type'     => 'AND',
		'children' => [
			['field' => 'place', 'operator' => 'eq', 'value' => 'Zuiddrecht'],
			[
				'type'     => 'OR',
				'children' => [
					['field' => 'industry', 'operator' => 'eq', 'value' => 'horeca'],
					['field' => 'industry', 'operator' => 'eq', 'value' => 'detailhandel'],
				],
			],
		],
	];

	/** @var string[] */
	private array $captured = [];

	private function handler(): MagicSearchHandler {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$relatedRows = $this->createMock(RelatedRowQueryApplier::class);
		$relatedRows->method('mayReadProperty')->willReturnCallback(static fn (Schema $schema, string $property): bool => $property !== 'secret');

		return new MagicSearchHandler(
			db: $db,
			logger: $this->createMock(LoggerInterface::class),
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: $this->createMock(DateTimeNormalizer::class),
			relatedRows: $relatedRows
		);
	}

	private function table(): MagicReadTable {
		$schema = new Schema();
		$schema->setProperties(self::PROPERTIES);
		return new MagicReadTable(schema: $schema);
	}

	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'in'] as $operator) {
			$expr->method($operator)->willReturnCallback(static fn ($column, $value): string => "{$operator}({$column},{$value})");
		}

		$expr->method('andX')->willReturnCallback(static fn (...$parts): ICompositeExpression => self::composite(type: 'AND', parts: $parts));
		$expr->method('orX')->willReturnCallback(static fn (...$parts): ICompositeExpression => self::composite(type: 'OR', parts: $parts));

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => ':' . json_encode($value));
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					$this->captured[] = (string)$predicate;
				}

				return $qb;
			}
		);

		return $qb;
	}

	/**
	 * A composite expression that renders as TYPE(part;part).
	 *
	 * @param string       $type  AND or OR.
	 * @param array<mixed> $parts The parts.
	 *
	 * @return ICompositeExpression
	 */
	private static function composite(string $type, array $parts): ICompositeExpression {
		return new class ($type . '(' . implode(';', array_map('strval', $parts)) . ')') implements ICompositeExpression {
			public function __construct(private readonly string $rendered) {
			}

			public function addMultiple(array $parts = []): ICompositeExpression {
				return $this;
			}

			public function add($part): ICompositeExpression {
				return $this;
			}

			public function count(): int {
				return 1;
			}

			public function getType(): string {
				return '';
			}

			public function __toString(): string {
				return $this->rendered;
			}
		};
	}

	/**
	 * What the QueryBuilder path adds for a query.
	 *
	 * @param array<string, mixed> $query The request query.
	 *
	 * @return string[]
	 */
	private function builder(array $query): array {
		$this->captured = [];
		(new ReflectionMethod(MagicSearchHandler::class, 'applyContentFilters'))->invoke(
			$this->handler(),
			new TableFilterRun(qb: $this->queryBuilder(), query: $query, table: $this->table())
		);

		return $this->captured;
	}

	/**
	 * What the raw-SQL path builds for a query.
	 *
	 * @param array<string, mixed> $query The request query.
	 *
	 * @return string[]
	 */
	private function raw(array $query): array {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($value): string => "'" . (string)$value . "'");

		return (new ReflectionMethod(MagicSearchHandler::class, 'buildObjectFilterConditionsSql'))->invoke(
			$this->handler(),
			new ConditionSqlRun(query: $query, table: $this->table(), connection: $connection, isPostgres: true)
		);
	}

	public function testTheTreeIsOneExpressionOfTheFlatFiltersOwnConditions(): void {
		$place  = $this->builder(['place' => 'Zuiddrecht'])[0];
		$horeca = $this->builder(['industry' => 'horeca'])[0];
		$retail = $this->builder(['industry' => 'detailhandel'])[0];

		$this->assertSame(["AND({$place};OR({$horeca};{$retail}))"], $this->builder(['_where' => json_encode(self::TREE)]));
		$this->assertSame(["AND({$place};OR({$horeca};{$retail}))"], $this->builder(['_where' => self::TREE]), 'a POST body carries it decoded');
	}

	public function testTheTreeIsAddedAfterTheFlatFilters(): void {
		$captured = $this->builder(['place' => 'Utrecht', '_where' => ['type' => 'OR', 'children' => [['field' => 'industry', 'operator' => 'eq', 'value' => 'horeca']]]]);

		$this->assertCount(2, $captured);
		$this->assertSame($this->builder(['place' => 'Utrecht'])[0], $captured[0]);
		$this->assertStringStartsWith('OR(', $captured[1]);
	}

	public function testALeafOnAPropertyTheCallerMayNotReadMatchesNothing(): void {
		$this->assertSame(['OR(1 = 0)'], $this->builder(['_where' => ['type' => 'OR', 'children' => [['field' => 'secret.name', 'operator' => 'eq', 'value' => 'x']]]]));
	}

	public function testWithoutATreeNothingChanges(): void {
		$this->assertSame($this->builder(['place' => 'Utrecht']), $this->builder(['place' => 'Utrecht', '_where' => '']));
		$this->assertSame($this->raw(['place' => 'Utrecht']), $this->raw(['place' => 'Utrecht', '_where' => null]));
	}

	public function testTheRawPathJoinsTheSameLeavesInParentheses(): void {
		$place  = $this->raw(['place' => 'Zuiddrecht'])[0];
		$horeca = $this->raw(['industry' => 'horeca'])[0];
		$retail = $this->raw(['industry' => 'detailhandel'])[0];

		$this->assertSame(["(({$place}) AND (({$horeca}) OR ({$retail})))"], $this->raw(['_where' => json_encode(self::TREE)]));
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedLeaves(): array {
		return [
			'undeclared' => [['field' => 'nope', 'operator' => 'eq', 'value' => 'x'], 'The schema does not declare the property nope'],
			'encrypted'  => [['field' => 'sealed', 'operator' => 'eq', 'value' => 'x'], 'The property sealed is encrypted and cannot be filtered'],
			'metadata'   => [['field' => '@self.owner', 'operator' => 'eq', 'value' => 'x'], 'A _where leaf on a metadata column is not supported yet: @self.owner'],
		];
	}

	/**
	 * @param array<string, mixed> $leaf    The refused leaf.
	 * @param string               $message The refusal.
	 *
	 * @dataProvider refusedLeaves
	 */
	public function testALeafTheSchemaCannotAnswerIsRefusedWithItsPathOnBothPaths(array $leaf, string $message): void {
		$tree = ['type' => 'AND', 'children' => [['field' => 'place', 'operator' => 'eq', 'value' => 'x'], ['type' => 'OR', 'children' => [$leaf]]]];
		foreach (['builder', 'raw'] as $path) {
			$this->captured = [];
			try {
				$this->{$path}(['_where' => $tree]);
				$this->fail($path . ': the leaf must be refused');
			} catch (ConditionGroupException $e) {
				$this->assertSame(['error' => $message, 'path' => '$.children[1].children[0]'], $e->toResponseBody(), $path);
				$this->assertNotContains('AND', array_map(static fn (string $c): string => substr($c, 0, 3), $this->captured), $path);
			}
		}
	}
}
