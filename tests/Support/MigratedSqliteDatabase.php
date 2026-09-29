<?php

/**
 * A SQLite database whose tables are built by this app's own migrations.
 *
 * Mapper queries are usually tested against a mocked query builder, which
 * accepts any column name. That is how `AuditTrailMapper::findByObjectUntil()`
 * shipped a filter on a column the table never had (#4161). This support class
 * runs every `lib/Migration/Version*::changeSchema()` against a real Doctrine
 * schema, creates the resulting tables in an in-memory SQLite database, and
 * hands out an `IDBConnection` whose query builder forwards to Doctrine's own,
 * so a mapper's query is executed as SQL against the table the migrations define.
 *
 * Only the query-builder methods listed in BRIDGED are forwarded; every other
 * method throws, so a query that needs more than the bridge knows fails loudly
 * instead of receiving a mocked default.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder as DoctrineQueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

class MigratedSqliteDatabase {
	/**
	 * Query-builder methods forwarded to Doctrine. Everything else throws.
	 */
	private const BRIDGED = [
		'select', 'from', 'where', 'andWhere', 'orWhere', 'orderBy', 'addOrderBy',
		'setMaxResults', 'setFirstResult', 'createNamedParameter', 'createFunction',
		'expr', 'executeQuery', 'getSQL',
	];

	/**
	 * Expression-builder methods forwarded to Doctrine. Everything else throws.
	 */
	private const BRIDGED_EXPR = ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'isNull', 'isNotNull', 'in'];

	private Connection $connection;

	/**
	 * Run the migrations and create the named tables in a fresh in-memory database.
	 *
	 * @param TestCase $test   The test that owns the mocks.
	 * @param string[] $tables Table names without prefix, as the migrations name them.
	 */
	public function __construct(private readonly TestCase $test, array $tables) {
		$schema = self::migratedSchema(test: $test);

		$this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$platform = $this->connection->getDatabasePlatform();
		foreach ($tables as $name) {
			foreach ($platform->getCreateTableSQL($schema->getTable($name)) as $sql) {
				$this->connection->executeStatement($sql);
			}
		}
	}//end __construct()

	/**
	 * The Doctrine connection, for inserting fixture rows.
	 *
	 * @return Connection
	 */
	public function connection(): Connection {
		return $this->connection;
	}//end connection()

	/**
	 * Insert a row, filling every NOT NULL column without a default that the row leaves out.
	 *
	 * @param string $table The table.
	 * @param array  $row   Column => value.
	 *
	 * @return void
	 */
	public function insert(string $table, array $row): void {
		foreach ($this->connection->createSchemaManager()->listTableColumns($table) as $column) {
			$name = trim($column->getName(), '"`');
			if (array_key_exists($name, $row) === true || $column->getNotnull() === false
				|| $column->getDefault() !== null || $column->getAutoincrement() === true
			) {
				continue;
			}

			$row[$name] = match ($column->getType()::class) {
				\Doctrine\DBAL\Types\IntegerType::class, \Doctrine\DBAL\Types\BigIntType::class,
				\Doctrine\DBAL\Types\SmallIntType::class, \Doctrine\DBAL\Types\BooleanType::class => 0,
				\Doctrine\DBAL\Types\DateTimeType::class => '2026-01-01 00:00:00',
				default => '',
			};
		}

		$quoted = [];
		foreach ($row as $name => $value) {
			$quoted[$this->connection->quoteIdentifier($name)] = $value;
		}

		$this->connection->insert($table, $quoted);
	}//end insert()

	/**
	 * An IDBConnection whose getQueryBuilder() runs real SQL on this database.
	 *
	 * @return IDBConnection
	 */
	public function idbConnection(): IDBConnection {
		$db = self::mock(test: $this->test, class: IDBConnection::class, bridged: ['getQueryBuilder']);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());

		return $db;
	}//end idbConnection()

	/**
	 * Build the schema by running every migration's changeSchema() in order.
	 *
	 * @param TestCase $test The test that owns the mocks.
	 *
	 * @return Schema
	 */
	public static function migratedSchema(TestCase $test): Schema {
		$schema = new Schema();
		$wrapper = self::schemaWrapper(test: $test, schema: $schema);
		$output = self::mock(test: $test, class: IOutput::class, bridged: null);

		$files = glob(dirname(__DIR__, 2) . '/lib/Migration/Version*.php');
		sort($files);
		foreach ($files as $file) {
			$class = 'OCA\\OpenRegister\\Migration\\' . basename($file, '.php');
			$migration = self::instantiate(test: $test, class: $class);
			$migration->changeSchema($output, static fn (): ISchemaWrapper => $wrapper, []);
		}

		return $schema;
	}//end migratedSchema()

	/**
	 * An ISchemaWrapper over a Doctrine schema, without a table prefix.
	 *
	 * @param TestCase $test   The test that owns the mocks.
	 * @param Schema   $schema The schema the migrations write to.
	 *
	 * @return ISchemaWrapper
	 */
	private static function schemaWrapper(TestCase $test, Schema $schema): ISchemaWrapper {
		$wrapper = self::mock(
			test: $test,
			class: ISchemaWrapper::class,
			bridged: ['getTable', 'hasTable', 'createTable', 'dropTable', 'getTables', 'getTableNames', 'getTableNamesWithoutPrefix', 'getDatabasePlatform']
		);
		$wrapper->method('getTable')->willReturnCallback(fn ($name) => self::table(table: $schema->getTable($name)));
		$wrapper->method('hasTable')->willReturnCallback(fn ($name) => $schema->hasTable($name));
		$wrapper->method('createTable')->willReturnCallback(fn ($name) => self::table(table: $schema->createTable($name)));
		$wrapper->method('dropTable')->willReturnCallback(
			function ($name) use ($schema, &$wrapper) {
				$schema->dropTable($name);
				return $wrapper;
			}
		);
		$wrapper->method('getTables')->willReturnCallback(
			fn () => array_values(array_map(fn ($table) => self::table(table: $table), $schema->getTables()))
		);
		$names = fn () => array_map(fn ($table) => $table->getName(), $schema->getTables());
		$wrapper->method('getTableNames')->willReturnCallback($names);
		$wrapper->method('getTableNamesWithoutPrefix')->willReturnCallback($names);
		$wrapper->method('getDatabasePlatform')->willReturn(new \Doctrine\DBAL\Platforms\SqlitePlatform());

		return $wrapper;
	}//end schemaWrapper()

	/**
	 * A table in the shape the running Nextcloud's ISchemaWrapper returns.
	 *
	 * Up to Nextcloud 34 the wrapper hands out the Doctrine table itself. From
	 * Nextcloud 35 `getTable()`/`createTable()` are typed `OCP\DB\Schema\ITable`,
	 * and the real wrapper wraps the Doctrine table in `OC\DB\Schema\Table`.
	 * A mock returning the bare Doctrine table there fails its own return type,
	 * which is how every test on this class errored on the stable35 CI cell.
	 *
	 * @param \Doctrine\DBAL\Schema\Table $table The Doctrine table.
	 *
	 * @return object The Doctrine table, or its NC 35 wrapper.
	 */
	private static function table(\Doctrine\DBAL\Schema\Table $table): object {
		$returnType = (new \ReflectionMethod(ISchemaWrapper::class, 'createTable'))->getReturnType();
		$wants      = null;
		if ($returnType instanceof ReflectionNamedType) {
			$wants = $returnType->getName();
		}

		if ($wants === null || $table instanceof $wants) {
			return $table;
		}

		if (class_exists(\OC\DB\Schema\Table::class) === false) {
			throw new \RuntimeException(
				'ISchemaWrapper returns ' . $wants . ' but OC\\DB\\Schema\\Table is not loadable to wrap the Doctrine table.'
			);
		}

		return new \OC\DB\Schema\Table($table);
	}//end table()

	/**
	 * Instantiate a migration with mocks for its constructor arguments.
	 *
	 * @param TestCase $test  The test that owns the mocks.
	 * @param string   $class The migration class.
	 *
	 * @return object
	 */
	private static function instantiate(TestCase $test, string $class): object {
		$constructor = (new ReflectionClass($class))->getConstructor();
		if ($constructor === null) {
			return new $class();
		}

		$args = [];
		foreach ($constructor->getParameters() as $parameter) {
			$type = $parameter->getType();
			if ($parameter->isDefaultValueAvailable() === true) {
				break;
			}

			if ($type instanceof ReflectionNamedType && $type->isBuiltin() === false) {
				$args[] = self::mock(test: $test, class: $type->getName(), bridged: null);
				continue;
			}

			$args[] = null;
		}

		return new $class(...$args);
	}//end instantiate()

	/**
	 * A query builder that forwards the bridged methods to Doctrine's.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$inner = $this->connection->createQueryBuilder();
		$qb = self::mock(test: $this->test, class: IQueryBuilder::class, bridged: self::BRIDGED);
		$expr = $this->expressionBuilder(inner: $inner);

		foreach (['select', 'from', 'where', 'andWhere', 'orWhere', 'orderBy', 'addOrderBy', 'setMaxResults', 'setFirstResult'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($inner, $method, $qb) {
					$inner->$method(...array_map(fn ($arg) => self::sql($arg), $args));
					return $qb;
				}
			);
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			fn ($value, $type = IQueryBuilder::PARAM_STR) => self::parameter($inner->createNamedParameter($value, $type))
		);
		$qb->method('createFunction')->willReturnCallback(fn (string $call) => self::func($call));
		$qb->method('getSQL')->willReturnCallback(fn () => self::unprefix($inner->getSQL()));
		$qb->method('executeQuery')->willReturnCallback(
			fn () => $this->result(rows: $this->connection->fetchAllAssociative(self::unprefix($inner->getSQL()), $inner->getParameters(), $inner->getParameterTypes()))
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * An expression builder that forwards the bridged methods to Doctrine's.
	 *
	 * @param DoctrineQueryBuilder $inner The Doctrine builder.
	 *
	 * @return IExpressionBuilder
	 */
	private function expressionBuilder(DoctrineQueryBuilder $inner): IExpressionBuilder {
		$expr = self::mock(test: $this->test, class: IExpressionBuilder::class, bridged: self::BRIDGED_EXPR);
		$doctrine = $inner->expr();
		foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte'] as $method) {
			$expr->method($method)->willReturnCallback(fn ($x, $y) => $doctrine->$method(self::sql($x), self::sql($y)));
		}

		$expr->method('isNull')->willReturnCallback(fn ($x) => $doctrine->isNull(self::sql($x)));
		$expr->method('isNotNull')->willReturnCallback(fn ($x) => $doctrine->isNotNull(self::sql($x)));
		$expr->method('in')->willReturnCallback(fn ($x, $y) => $doctrine->in(self::sql($x), self::sql($y)));

		return $expr;
	}//end expressionBuilder()

	/**
	 * Wrap fetched rows as an IResult.
	 *
	 * @param array $rows The rows.
	 *
	 * @return IResult
	 */
	private function result(array $rows): IResult {
		// Nextcloud 35's QBMapper reads with fetchAssociative(); older releases
		// use fetch(). Bridge whichever the loaded IResult declares, so the same
		// test runs against both.
		$single = array_values(array_filter(['fetch', 'fetchAssociative'], fn ($m) => method_exists(IResult::class, $m)));
		$all    = array_values(array_filter(['fetchAll', 'fetchAllAssociative'], fn ($m) => method_exists(IResult::class, $m)));
		$result = self::mock(test: $this->test, class: IResult::class, bridged: array_merge($single, $all, ['closeCursor']));
		foreach ($single as $method) {
			$result->method($method)->willReturnCallback(
				function () use (&$rows) {
					$row = array_shift($rows);
					return $row ?? false;
				}
			);
		}

		foreach ($all as $method) {
			$result->method($method)->willReturnCallback(
				function () use (&$rows) {
					$remaining = $rows;
					$rows      = [];
					return $remaining;
				}
			);
		}

		$result->method('closeCursor')->willReturn(true);

		return $result;
	}//end result()

	/**
	 * Convert a builder argument to SQL text.
	 *
	 * @param mixed $value The argument.
	 *
	 * @return mixed
	 */
	private static function sql(mixed $value): mixed {
		if ($value instanceof IParameter || $value instanceof IQueryFunction) {
			return (string) $value;
		}

		return $value;
	}//end sql()

	/**
	 * Drop the table prefix placeholder; the test tables have no prefix.
	 *
	 * @param string $sql The SQL.
	 *
	 * @return string
	 */
	private static function unprefix(string $sql): string {
		return str_replace('*PREFIX*', '', $sql);
	}//end unprefix()

	/**
	 * An IParameter holding a Doctrine placeholder.
	 *
	 * @param string $placeholder The placeholder.
	 *
	 * @return IParameter
	 */
	private static function parameter(string $placeholder): IParameter {
		return new class($placeholder) implements IParameter {
			public function __construct(private readonly string $placeholder) {
			}

			public function __toString() {
				return $this->placeholder;
			}
		};
	}//end parameter()

	/**
	 * An IQueryFunction holding raw SQL.
	 *
	 * @param string $call The SQL.
	 *
	 * @return IQueryFunction
	 */
	private static function func(string $call): IQueryFunction {
		return new class($call) implements IQueryFunction {
			public function __construct(private readonly string $call) {
			}

			public function __toString() {
				return $this->call;
			}
		};
	}//end func()

	/**
	 * A mock whose unbridged methods throw.
	 *
	 * @param TestCase      $test    The test that owns the mock.
	 * @param string        $class   The interface or class to mock.
	 * @param string[]|null $bridged Methods the caller configures; null leaves every method a plain stub.
	 *
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private static function mock(TestCase $test, string $class, ?array $bridged): \PHPUnit\Framework\MockObject\MockObject {
		$builder = (new \PHPUnit\Framework\MockObject\MockBuilder($test, $class))
			->disableOriginalConstructor()
			->disableOriginalClone();
		$mock = $builder->getMock();
		if ($bridged === null) {
			return $mock;
		}

		foreach ((new ReflectionClass($class))->getMethods() as $method) {
			$name = $method->getName();
			if (in_array($name, $bridged, true) === true || $method->isConstructor() === true || $method->isStatic() === true || $method->isFinal() === true) {
				continue;
			}

			$mock->method($name)->willThrowException(
				new \LogicException(sprintf('%s::%s is not bridged to the test database', $class, $name))
			);
		}

		return $mock;
	}//end mock()
}//end class
