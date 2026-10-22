<?php

/**
 * The rows the identifier lookup reads, and what it makes of them (#4037).
 *
 * The lookup is one OR(id, uuid, slug, uri) query. It must read the row matched
 * by its id or uuid first and in a stable order, and read a bounded number of
 * rows, without letting the bound turn an ambiguous slug into a single pick.
 * The query itself is simulated: a fake driver sorts and cuts the matching rows
 * the way the ORDER BY and LIMIT it was given would.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class MagicMapperIdentifierFetchTest extends TestCase {

	/** @var list<array<string, mixed>> Every row the WHERE clause matches, in table order. */
	private array $matching = [];

	/** @var string|null The SQL the lookup gave createFunction() for its ORDER BY. */
	private ?string $orderSql = null;

	/** @var int|null The row cap the lookup set. */
	private ?int $limit = null;

	/** @var list<array<string, mixed>> Rows handed to the conversion. */
	private array $converted = [];

	/** @var list<int> The values bound to the id condition. */
	private array $idParams = [];

	private int $identifierId = 0;

	private string $identifierText = '';

	/**
	 * A mapper over a fake driver; only the finder itself is real.
	 *
	 * @return MagicMapper&MockObject The mapper.
	 */
	private function makeMapper(): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());

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
					$this->createMock(LoggerInterface::class),
					$this->createMock(\OCA\OpenRegister\Service\SettingsService::class),
					$this->container(),
				]
			)
			->onlyMethods(['existsTableForRegisterSchema', 'getTableNameForRegisterSchema', 'convertRowToObjectEntity'])
			->getMock();
		$mapper->method('existsTableForRegisterSchema')->willReturn(true);
		$mapper->method('getTableNameForRegisterSchema')->willReturn('oc_openregister_table_1_2');
		$mapper->method('convertRowToObjectEntity')->willReturnCallback(
			function (array $row): ObjectEntity {
				$this->converted[] = $row;
				$entity = new ObjectEntity();
				$entity->setUuid((string)$row['_uuid']);
				return $entity;
			}
		);

		return $mapper;
	}//end makeMapper()

	/**
	 * The container the MagicMapper constructor resolves its helpers from.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				\OCA\OpenRegister\Service\DateTimeNormalizer::class => $this->createMock(\OCA\OpenRegister\Service\DateTimeNormalizer::class),
				\OCA\OpenRegister\Service\ConditionMatcher::class => $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class),
				\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class => $this->createMock(\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class),
				default => null,
			}
		);

		return $container;
	}//end container()

	/**
	 * A query builder that records ORDER BY / LIMIT and answers like a driver would.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$expr->method('orX')->willReturn($this->createMock(ICompositeExpression::class));

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('addOrderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnCallback(
			function (?int $limit) use ($qb): IQueryBuilder {
				$this->limit = $limit;
				return $qb;
			}
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = null): string {
				if ($type === IQueryBuilder::PARAM_INT) {
					$this->idParams[] = $value;
				}

				return ':p' . (is_scalar($value) ? $value : '');
			}
		);
		$qb->method('createFunction')->willReturnCallback(
			function (string $sql): IQueryFunction {
				$this->orderSql = $sql;
				return $this->createMock(IQueryFunction::class);
			}
		);
		$qb->method('executeQuery')->willReturnCallback(fn (): IResult => $this->simulatedResult());

		return $qb;
	}//end queryBuilder()

	/**
	 * The result of the simulated query: key rows first when an ORDER BY was given, cut to the limit.
	 *
	 * @return IResult The result.
	 */
	private function simulatedResult(): IResult {
		$rows = $this->matching;
		if ($this->orderSql !== null) {
			$isKey = fn (array $row): bool => ((int)$row['_id'] === $this->identifierId && is_numeric($this->identifierText))
				|| strcasecmp((string)$row['_uuid'], $this->identifierText) === 0;
			usort(
				$rows,
				static fn (array $a, array $b): array|int => [(int)(!$isKey($a)), (int)$a['_id']] <=> [(int)(!$isKey($b)), (int)$b['_id']]
			);
		}

		if ($this->limit !== null) {
			$rows = array_slice($rows, 0, $this->limit);
		}

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(
			static function () use (&$rows) {
				return array_shift($rows) ?? false;
			}
		);

		return $result;
	}//end simulatedResult()

	/**
	 * Find an identifier among the matching rows.
	 *
	 * @param string $identifier The identifier.
	 *
	 * @return ObjectEntity The object.
	 */
	private function find(string $identifier): ObjectEntity {
		$this->identifierText = $identifier;
		$this->identifierId = (int)$identifier;
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);

		return $this->makeMapper()->findInRegisterSchemaTable(
			identifier: $identifier,
			register: $register,
			schema: $schema,
			_rbac: false,
			_multitenancy: false
		);
	}//end find()

	/**
	 * Rows held by a slug.
	 *
	 * @param int    $from  First id.
	 * @param int    $count How many.
	 * @param string $slug  The slug.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	private function slugRows(int $from, int $count, string $slug): array {
		$rows = [];
		for ($id = $from; $id < ($from + $count); $id++) {
			$rows[] = ['_id' => $id, '_uuid' => 'uuid-' . $id, '_slug' => $slug];
		}

		return $rows;
	}//end slugRows()

	public function testTheLookupOrdersKeyMatchesFirstAndCapsTheRows(): void {
		$this->matching = [['_id' => 7, '_uuid' => 'uuid-7', '_slug' => 'x']];

		$this->find('7');

		$this->assertNotNull($this->orderSql, 'an ORDER BY puts the id/uuid match first');
		$this->assertStringContainsString('CASE WHEN', (string)$this->orderSql);
		$this->assertSame(50, $this->limit);
	}//end testTheLookupOrdersKeyMatchesFirstAndCapsTheRows()

	public function testAnIdMatchBeyondFiftySlugRowsIsStillResolved(): void {
		// Review finding r2routes-f7: 51 rows hold slug `2024`, the object with id 2024 comes last in table order.
		$this->matching = array_merge($this->slugRows(1, 51, '2024'), [['_id' => 2024, '_uuid' => 'uuid-2024', '_slug' => 'other']]);

		$entity = $this->find('2024');

		$this->assertSame('uuid-2024', $entity->getUuid());
	}//end testAnIdMatchBeyondFiftySlugRowsIsStillResolved()

	public function testMoreThanFiftySlugHoldersStayAmbiguous(): void {
		$this->matching = $this->slugRows(1, 60, 'hello');

		$this->expectException(MultipleObjectsReturnedException::class);

		$this->find('hello');
	}//end testMoreThanFiftySlugHoldersStayAmbiguous()

	public function testAZeroPaddedIdentifierIsASlugNotAnId(): void {
		// Review finding pp-f2: `007` is not the canonical integer 7. The id condition is bound to
		// -1 (it matches nothing), so the object with slug `007` is the answer.
		$this->matching = [['_id' => 3, '_uuid' => 'uuid-3', '_slug' => '007']];

		$entity = $this->find('007');

		$this->assertSame('uuid-3', $entity->getUuid());
		$this->assertSame([-1], $this->idParams);
	}//end testAZeroPaddedIdentifierIsASlugNotAnId()

	public function testACanonicalIntegerIsBoundAsTheId(): void {
		$this->matching = [['_id' => 7, '_uuid' => 'uuid-7', '_slug' => 'x']];

		$this->find('7');

		$this->assertSame([7], $this->idParams);
	}//end testACanonicalIntegerIsBoundAsTheId()
}//end class
