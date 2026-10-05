<?php

/**
 * MagicMapper::recordFolder() writes one column and dispatches no lifecycle event.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\SettingsService;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
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

/**
 * The folder id of an object is bookkeeping: one column, compare-and-set, no events.
 */
class MagicMapperRecordFolderTest extends TestCase {

	private IDBConnection&MockObject $db;

	private IEventDispatcher&MockObject $dispatcher;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	/** @var list<string> Every column the UPDATE sets. */
	private array $setColumns = [];

	/** @var list<mixed> Every value the UPDATE sets, in the order of $setColumns. */
	private array $setValues = [];

	/** @var list<string> Every table update() was called with. */
	private array $tables = [];

	/** @var list<string> The WHERE and AND WHERE conditions, rendered. */
	private array $conditions = [];

	/** @var array<int, string> Each OR composite the expression builder made, rendered, by object id. */
	private array $rendered = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		// No lifecycle event of any kind may leave this write.
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->dispatcher->expects($this->never())->method('dispatch');

		$register = new Register();
		$register->setId(7);
		$schema = new Schema();
		$schema->setId(3);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);
	}//end setUp()

	/**
	 * A real MagicMapper; only the table-name lookup (a cache write) is replaced.
	 *
	 * @return MagicMapper&MockObject
	 */
	private function makeMapper(): MagicMapper {
		// The collaborators MagicMapper pulls from the container while it is built.
		$collaborators = [
			DateTimeNormalizer::class => $this->createMock(DateTimeNormalizer::class),
			ConditionMatcher::class => $this->createMock(ConditionMatcher::class),
			SchemaTypeConverter::class => $this->createMock(SchemaTypeConverter::class),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($collaborators[$id] ?? null));

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs(
				[
					$this->db,
					$this->schemaMapper,
					$this->registerMapper,
					$this->createMock(IConfig::class),
					$this->dispatcher,
					$this->createMock(IUserSession::class),
					$this->createMock(IGroupManager::class),
					$this->createMock(IUserManager::class),
					$this->createMock(IAppConfig::class),
					$this->createMock(LoggerInterface::class),
					$this->createMock(SettingsService::class),
					$container,
				]
			)
			->onlyMethods(['getTableNameForRegisterSchema'])
			->getMock();
		$mapper->method('getTableNameForRegisterSchema')->willReturnCallback(
			fn (Register $register, Schema $schema): string => 'openregister_table_' . $register->getId() . '_' . $schema->getId()
		);

		return $mapper;
	}//end makeMapper()

	/**
	 * An UPDATE query builder that records what it was asked to write, and answers $affected rows.
	 *
	 * @param int $affected The row count executeStatement() reports.
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function updateQb(int $affected): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createNamedParameter')->willReturnCallback(fn ($value): string => 'p(' . var_export($value, true) . ')');

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(fn ($col, $val): string => $col . ' = ' . $val);
		$expr->method('isNull')->willReturnCallback(fn ($col): string => $col . ' IS NULL');
		$expr->method('orX')->willReturnCallback(
			function (...$parts): ICompositeExpression {
				$composite = $this->createMock(ICompositeExpression::class);
				$this->rendered[spl_object_id($composite)] = '(' . implode(' OR ', $parts) . ')';
				return $composite;
			}
		);
		$qb->method('expr')->willReturn($expr);

		$qb->method('update')->willReturnCallback(
			function (string $table) use (&$qb): IQueryBuilder {
				$this->tables[] = $table;
				return $qb;
			}
		);
		$qb->method('set')->willReturnCallback(
			function (string $column, $value) use (&$qb): IQueryBuilder {
				$this->setColumns[] = $column;
				$this->setValues[] = $value;
				return $qb;
			}
		);
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$conditions) use (&$qb): IQueryBuilder {
					foreach ($conditions as $condition) {
						if ($condition instanceof ICompositeExpression) {
							$condition = $this->rendered[spl_object_id($condition)];
						}

						$this->conditions[] = $condition;
					}

					return $qb;
				}
			);
		}

		$qb->expects($this->once())->method('executeStatement')->willReturn($affected);

		return $qb;
	}//end updateQb()

	/**
	 * An object of register 7, schema 3.
	 *
	 * @param string|null $uuid The object's uuid.
	 *
	 * @return ObjectEntity
	 */
	private function object(?string $uuid = 'case-1'): ObjectEntity {
		$object = new ObjectEntity();
		if ($uuid !== null) {
			$object->setUuid($uuid);
		}

		$object->setRegister('7');
		$object->setSchema('3');
		$object->setObject(['title' => 'A case', 'total' => 42]);

		return $object;
	}//end object()

	/**
	 * Only the `_folder` column of the one row is written, and only while it is still empty or as read.
	 *
	 * @return void
	 */
	public function testOnlyTheFolderColumnOfTheOneRowIsWritten(): void {
		$this->db->method('getQueryBuilder')->willReturn($this->updateQb(affected: 1));

		$recorded = $this->makeMapper()->recordFolder(entity: $this->object(), expected: null, folderId: '502');

		$this->assertTrue($recorded);
		$this->assertSame(['openregister_table_7_3'], $this->tables);
		$this->assertSame(['_folder'], $this->setColumns);
		$this->assertSame(["p('502')"], $this->setValues);
		$this->assertSame(
			["_uuid = p('case-1')", "(_folder IS NULL OR _folder = p(''))"],
			$this->conditions
		);
	}//end testOnlyTheFolderColumnOfTheOneRowIsWritten()

	/**
	 * A legacy path is replaced only while it is still the stored value.
	 *
	 * @return void
	 */
	public function testALegacyPathIsReplacedOnlyWhileItIsStillStored(): void {
		$this->db->method('getQueryBuilder')->willReturn($this->updateQb(affected: 1));

		$this->makeMapper()->recordFolder(entity: $this->object(), expected: 'Open Registers/x/case-1', folderId: '502');

		$this->assertContains("(_folder IS NULL OR _folder = p('Open Registers/x/case-1'))", $this->conditions);
	}//end testALegacyPathIsReplacedOnlyWhileItIsStillStored()

	/**
	 * When no row matched, another request recorded a folder first: the answer is false.
	 *
	 * @return void
	 */
	public function testAFolderRecordedByAnotherRequestFirstAnswersFalse(): void {
		$this->db->method('getQueryBuilder')->willReturn($this->updateQb(affected: 0));

		$this->assertFalse($this->makeMapper()->recordFolder(entity: $this->object(), expected: null, folderId: '502'));
	}//end testAFolderRecordedByAnotherRequestFirstAnswersFalse()

	/**
	 * An object without a uuid cannot be addressed, so nothing is written.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutAUuidIsRefused(): void {
		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Cannot record an object folder without the object uuid');

		$this->makeMapper()->recordFolder(entity: $this->object(uuid: null), expected: null, folderId: '502');
	}//end testAnObjectWithoutAUuidIsRefused()

	/**
	 * An object whose schema cannot be resolved has no table to write to, so nothing is written.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutResolvableContextIsRefused(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('find')->willThrowException(new Exception('no schema'));
		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Cannot record an object folder without register and schema context');

		$this->makeMapper()->recordFolder(entity: $this->object(), expected: null, folderId: '502');
	}//end testAnObjectWithoutResolvableContextIsRefused()
}//end class
