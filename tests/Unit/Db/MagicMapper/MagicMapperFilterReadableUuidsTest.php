<?php

/**
 * MagicMapper::filterReadableUuids() asks the object read rule, nothing else.
 *
 * The name lookup decides "may this caller see this name" by asking which
 * UUIDs of one table the caller may read. That question must be answered by
 * the same access-control filter the single-object read applies
 * (MagicSearchHandler::applyAccessControlToQuery with RBAC and multitenancy
 * on), and must answer "none" whenever it cannot be answered.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(MagicMapper::class)]
class MagicMapperFilterReadableUuidsTest extends TestCase {

	private IDBConnection&MockObject $db;

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private MagicSearchHandler&MockObject $searchHandler;

	private MagicTableHandler&MockObject $tableHandler;

	/**
	 * Every access-control call: schema id, flags and register id.
	 *
	 * @var array<int, array{schemaId: int|null, _rbac: bool, _multitenancy: bool, registerId: int|null}>
	 */
	private array $accessControlCalls = [];

	/**
	 * The UUID chunk bound into each query, in order.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $boundChunks = [];

	/**
	 * The UUIDs the stubbed table hands back after the access filter.
	 *
	 * @var array<int, string>
	 */
	private array $admitted = [];

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->searchHandler = $this->createMock(MagicSearchHandler::class);
		$this->tableHandler = $this->createMock(MagicTableHandler::class);

		$register = new Register();
		$register->setId(26);
		$schema = new Schema();
		$schema->setId(961);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->tableHandler->method('getTableNameForRegisterSchema')->willReturn('openregister_table_26_961');

		$this->searchHandler->method('applyAccessControlToQuery')->willReturnCallback(
			function (IQueryBuilder $qb, Schema $schema, bool $_rbac = true, bool $_multitenancy = true, ?int $registerId = null): void {
				$this->accessControlCalls[] = [
					'schemaId' => $schema->getId(),
					'_rbac' => $_rbac,
					'_multitenancy' => $_multitenancy,
					'registerId' => $registerId,
				];
			}
		);

		$this->db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->makeQueryBuilder());
	}//end setUp()

	private function makeMapper(bool $tableExists = true): MagicMapper {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				\OCA\OpenRegister\Service\DateTimeNormalizer::class => $this->createMock(\OCA\OpenRegister\Service\DateTimeNormalizer::class),
				\OCA\OpenRegister\Service\ConditionMatcher::class => $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class),
				\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class => $this->createMock(\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class),
				default => null,
			}
		);

		$mapper = new MagicMapper(
			$this->db,
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(IConfig::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\OpenRegister\Service\SettingsService::class),
			$container
		);

		$this->tableHandler->method('existsTableForRegisterSchema')->willReturn($tableExists);

		foreach (['tableHandler' => $this->tableHandler, 'searchHandler' => $this->searchHandler] as $property => $value) {
			$reflection = new \ReflectionProperty(MagicMapper::class, $property);
			$reflection->setAccessible(true);
			$reflection->setValue($mapper, $value);
		}

		return $mapper;
	}//end makeMapper()

	private function makeQueryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturn('in');
		$expr->method('isNull')->willReturn('isNull');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value): string {
				$this->boundChunks[] = $value;
				return ':uuids';
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function (): IResult {
				$chunk = end($this->boundChunks);
				$rows = array_map(
					fn (string $uuid): array => ['_uuid' => $uuid],
					array_values(array_intersect($chunk, $this->admitted))
				);
				$rows[] = false;
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
				return $result;
			}
		);

		return $qb;
	}//end makeQueryBuilder()

	/**
	 * The answer is what the access-control filter lets through, asked with RBAC
	 * and multitenancy on and the table's own register.
	 */
	public function testReturnsWhatTheReadRuleAdmits(): void {
		$this->admitted = ['uuid-readable'];

		$readable = $this->makeMapper()->filterReadableUuids(26, 961, ['uuid-readable', 'uuid-refused', 'uuid-readable']);

		$this->assertSame(['uuid-readable'], $readable);
		$this->assertSame(
			[['schemaId' => 961, '_rbac' => true, '_multitenancy' => true, 'registerId' => 26]],
			$this->accessControlCalls,
			'The read rule must be applied, with both flags on, for the table\'s register.'
		);
		$this->assertSame([['uuid-readable', 'uuid-refused']], $this->boundChunks, 'Duplicates are asked once.');
	}//end testReturnsWhatTheReadRuleAdmits()

	/**
	 * Nothing to ask means no query at all.
	 */
	public function testAnEmptyListAsksNothing(): void {
		$this->assertSame([], $this->makeMapper()->filterReadableUuids(26, 961, ['', '']));
		$this->assertSame([], $this->accessControlCalls);
	}//end testAnEmptyListAsksNothing()

	/**
	 * A table that does not exist holds nothing readable.
	 */
	public function testAMissingTableAdmitsNothing(): void {
		$this->admitted = ['uuid-readable'];

		$this->assertSame([], $this->makeMapper(tableExists: false)->filterReadableUuids(26, 961, ['uuid-readable']));
		$this->assertSame([], $this->accessControlCalls);
	}//end testAMissingTableAdmitsNothing()

	/**
	 * When read access cannot be established, nothing is disclosed.
	 */
	public function testAFailureAdmitsNothing(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willThrowException(new \RuntimeException('register gone'));
		$this->registerMapper = $registerMapper;

		$this->assertSame([], $this->makeMapper()->filterReadableUuids(26, 961, ['uuid-readable']));
	}//end testAFailureAdmitsNothing()

	/**
	 * A long list is asked in chunks, each through the read rule.
	 */
	public function testALongListIsAskedInChunks(): void {
		$uuids = array_map(fn (int $i): string => sprintf('uuid-%04d', $i), range(1, 1001));
		$this->admitted = ['uuid-0001', 'uuid-0501', 'uuid-1001'];

		$readable = $this->makeMapper()->filterReadableUuids(26, 961, $uuids);

		$this->assertSame(['uuid-0001', 'uuid-0501', 'uuid-1001'], $readable);
		$this->assertCount(3, $this->boundChunks);
		$this->assertCount(3, $this->accessControlCalls, 'Every chunk goes through the read rule.');
	}//end testALongListIsAskedInChunks()
}//end class
