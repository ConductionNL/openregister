<?php

/**
 * Characterisation: the operator-bag and translatable-sort SQL of MagicSearchHandler.
 *
 * Pins, branch by branch, the exact predicates three builders return so that
 * splitting them into named steps cannot change a query: the metadata operator
 * bag on the QueryBuilder path, the object-field operator bag on the raw UNION
 * path, and the translatable ORDER BY expression for every platform.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper\BoundQueryColumn;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\RawObjectFieldFilter;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden predicates for metadataOperatorPredicates(), objectOperatorConditionsSql()
 * and buildTranslatableSortSql().
 */
class MagicSearchHandlerOperatorBagsGoldenTest extends TestCase {

	/**
	 * Build a handler on a connection that reports the given platform.
	 *
	 * @param AbstractPlatform $platform The platform the connection reports.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handler(AbstractPlatform $platform): MagicSearchHandler {
		$logger = $this->createMock(LoggerInterface::class);
		$db     = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);

		return new MagicSearchHandler(
			db: $db,
			logger: $logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);
	}//end handler()

	/**
	 * A query builder whose expressions render as `op(column,value)` and whose
	 * named parameters render as `:pN=value`, numbered in binding order.
	 *
	 * @return IQueryBuilder The query builder double.
	 */
	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'in', 'notIn', 'like', 'iLike'] as $operator) {
			$expr->method($operator)->willReturnCallback(
				static fn (string $column, $value): string => "{$operator}({$column},{$value})"
			);
		}

		$expr->method('isNull')->willReturnCallback(static fn (string $c): string => "isNull({$c})");
		$expr->method('isNotNull')->willReturnCallback(static fn (string $c): string => "isNotNull({$c})");

		$counter = 0;
		$qb      = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value, $type = null) use (&$counter): string {
				$counter++;
				if (is_array($value) === true) {
					$value = '['.implode('|', array_map('strval', $value)).']';
				}

				return ":p{$counter}=".var_export($value, true).'/'.var_export($type, true);
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * One case per branch of the metadata operator bag.
	 *
	 * @return array<string, array{0: array, 1: list<string>}>
	 */
	public static function metadataBags(): array {
		$str = IQueryBuilder::PARAM_STR_ARRAY;
		return [
			'like only'                => [['like' => 'ab'], ["(LOWER(CAST(t._name AS TEXT)) LIKE LOWER(:p1='%ab%'/2) ESCAPE '\\')"]],
			'like with no term left'   => [['like' => ''], []],
			'like and gte'             => [['like' => 'ab', 'gte' => '1'], ["(LOWER(CAST(t._name AS TEXT)) LIKE LOWER(:p1='%ab%'/2) ESCAPE '\\')", "gte(t._name,:p2='1'/2)"]],
			'bare list'                => [['a', 'b'], ["in(t._name,:p1='[a|b]'/{$str})"]],
			'like and bare list'       => [['like' => 'ab', 0 => 'x'], ["(LOWER(CAST(t._name AS TEXT)) LIKE LOWER(:p1='%ab%'/2) ESCAPE '\\')", "in(t._name,:p2='[x]'/{$str})"]],
			'every comparison'         => [
				['ne' => 'e', 'lt' => 'd', 'gt' => 'c', 'lte' => 'b', 'gte' => 'a'],
				["gte(t._name,:p1='a'/2)", "lte(t._name,:p2='b'/2)", "gt(t._name,:p3='c'/2)", "lt(t._name,:p4='d'/2)", "neq(t._name,:p5='e'/2)"],
			],
			'in scalar'                => [['in' => 'x'], ["in(t._name,:p1='[x]'/{$str})"]],
			'in list'                  => [['in' => ['x', 'y']], ["in(t._name,:p1='[x|y]'/{$str})"]],
			'notIn scalar'             => [['notIn' => 'x'], ["notIn(t._name,:p1='[x]'/{$str})"]],
			'notIn list'               => [['notIn' => ['x', 'y']], ["notIn(t._name,:p1='[x|y]'/{$str})"]],
			'notIn empty list'         => [['notIn' => []], []],
			'isnull true'              => [['isnull' => 'true'], ['isNull(t._name)']],
			'isnull false'             => [['isnull' => 'false'], ['isNotNull(t._name)']],
			'null in and isnull null'  => [['in' => null, 'isnull' => null, 'lt' => null], []],
			'all of them in order'     => [
				['isnull' => '1', 'notIn' => ['n'], 'in' => ['i'], 'gte' => 'g'],
				["gte(t._name,:p1='g'/2)", "in(t._name,:p2='[i]'/{$str})", "notIn(t._name,:p3='[n]'/{$str})", 'isNull(t._name)'],
			],
		];
	}//end metadataBags()

	/**
	 * The metadata operator bag yields the same predicates, in the same order,
	 * with the same parameters bound in the same order.
	 *
	 * @param array        $bag      The operator bag.
	 * @param list<string> $expected The rendered predicates.
	 *
	 * @return void
	 */
	#[DataProvider('metadataBags')]
	public function testMetadataOperatorPredicatesMatchGolden(array $bag, array $expected): void {
		$method     = new ReflectionMethod(MagicSearchHandler::class, 'metadataOperatorPredicates');
		$predicates = $method->invoke(
			$this->handler(new SqlitePlatform()),
			new BoundQueryColumn(qb: $this->queryBuilder(), ref: 't._name'),
			$bag
		);

		$this->assertSame($expected, array_map('strval', $predicates));
	}//end testMetadataOperatorPredicatesMatchGolden()

	/**
	 * One case per branch of the raw-path object operator bag.
	 *
	 * @return array<string, array{0: array, 1: string, 2: bool, 3: list<string>}>
	 */
	public static function objectBags(): array {
		return [
			'every comparison, text'      => [
				['ne' => 'e', 'lt' => 'd', 'gt' => 'c', 'lte' => 'b', 'gte' => 'a'],
				'string',
				true,
				["\"c\" >= 'a'", "\"c\" <= 'b'", "\"c\" > 'c'", "\"c\" < 'd'", "\"c\" <> 'e'"],
			],
			'numeric column, uuid value'  => [['gte' => 'abc', 'lt' => '5'], 'integer', true, ["\"c\"::text >= 'abc'", "\"c\" < '5'"]],
			'numeric column, mysql'       => [['gte' => 'abc'], 'integer', false, ["CAST(\"c\" AS CHAR) >= 'abc'"]],
			'in scalar'                   => [['in' => 'x'], 'string', true, ["\"c\" IN ('x')"]],
			'in list'                     => [['in' => ['x', 'y']], 'string', true, ["\"c\" IN ('x', 'y')"]],
			'in numeric with text'        => [['in' => ['1', 'y']], 'integer', true, ["\"c\"::text IN ('1', 'y')"]],
			'notIn scalar'                => [['notIn' => 'x'], 'string', true, ["\"c\" NOT IN ('x')"]],
			'notIn list'                  => [['notIn' => ['x', 'y']], 'string', true, ["\"c\" NOT IN ('x', 'y')"]],
			'notIn empty'                 => [['notIn' => []], 'string', true, []],
			'isnull true, no cast'        => [['isnull' => 'true'], 'integer', true, ['"c" IS NULL']],
			'isnull false'                => [['isnull' => '0'], 'string', true, ['"c" IS NOT NULL']],
			'null values skipped'         => [['gte' => null, 'in' => null, 'notIn' => null, 'ne' => null, 'isnull' => null], 'string', true, []],
			'all of them in order'        => [
				['isnull' => 'yes', 'ne' => 'n', 'notIn' => ['o'], 'in' => ['i'], 'gte' => 'g'],
				'string',
				true,
				["\"c\" >= 'g'", "\"c\" IN ('i')", "\"c\" NOT IN ('o')", "\"c\" <> 'n'", '"c" IS NULL'],
			],
		];
	}//end objectBags()

	/**
	 * The raw-path object operator bag yields the same conditions in the same order.
	 *
	 * @param array        $bag        The operator bag.
	 * @param string       $type       The property's declared type.
	 * @param bool         $isPostgres Whether the platform is PostgreSQL.
	 * @param list<string> $expected   The conditions.
	 *
	 * @return void
	 */
	#[DataProvider('objectBags')]
	public function testObjectOperatorConditionsSqlMatchGolden(array $bag, string $type, bool $isPostgres, array $expected): void {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($v): string => "'{$v}'");

		$method     = new ReflectionMethod(MagicSearchHandler::class, 'objectOperatorConditionsSql');
		$conditions = $method->invoke(
			$this->handler(new SqlitePlatform()),
			$bag,
			new RawObjectFieldFilter(quotedCol: '"c"', propertyType: $type, connection: $connection, isPostgres: $isPostgres)
		);

		$this->assertSame($expected, $conditions);
	}//end testObjectOperatorConditionsSqlMatchGolden()

	/**
	 * One case per platform branch of the translatable sort expression.
	 *
	 * @return array<string, array{0: AbstractPlatform, 1: string}>
	 */
	public static function sortPlatforms(): array {
		$pg = "CASE WHEN CAST(c AS TEXT) LIKE '{%' THEN COALESCE("
			."substring(CAST(c AS TEXT) from '\"nl\"\\s*:\\s*\"((?:[^\"\\\\]|\\\\.)*)\"'), "
			."substring(CAST(c AS TEXT) from '\"en-gb\"\\s*:\\s*\"((?:[^\"\\\\]|\\\\.)*)\"'), "
			."substring(CAST(c AS TEXT) from '^\\{\\s*\"[^\"]*\"\\s*:\\s*\"((?:[^\"\\\\]|\\\\.)*)\"'), "
			."CAST(c AS TEXT)) ELSE CAST(c AS TEXT) END";
		$my = "CASE WHEN JSON_VALID(c) THEN CASE WHEN JSON_TYPE(c) = 'OBJECT' THEN COALESCE("
			."JSON_UNQUOTE(JSON_EXTRACT(c, '$.\"nl\"')), JSON_UNQUOTE(JSON_EXTRACT(c, '$.\"en-gb\"')), "
			."JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(c, '$.*'), '$[0]')), c) ELSE c END ELSE c END";
		$lite = "CASE WHEN json_valid(c) THEN CASE WHEN json_type(c) = 'object' THEN COALESCE("
			."json_extract(c, '$.\"nl\"'), json_extract(c, '$.\"en-gb\"'), "
			."(SELECT value FROM json_each(c) LIMIT 1), c) ELSE c END ELSE c END";

		return [
			'postgres' => [new PostgreSQLPlatform(), $pg],
			'mysql'    => [new MySQLPlatform(), $my],
			'mariadb'  => [new MariaDBPlatform(), $my],
			'sqlite'   => [new SqlitePlatform(), $lite],
			'other'    => [new OraclePlatform(), 'c'],
		];
	}//end sortPlatforms()

	/**
	 * The translatable sort expression per platform, with the language chain
	 * cleaned, lower-cased and de-duplicated.
	 *
	 * @param AbstractPlatform $platform The platform.
	 * @param string           $expected The expression.
	 *
	 * @return void
	 */
	#[DataProvider('sortPlatforms')]
	public function testBuildTranslatableSortSqlMatchesGolden(AbstractPlatform $platform, string $expected): void {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildTranslatableSortSql');
		$sql    = $method->invoke($this->handler($platform), 'c', ['NL', 'nl', '!!', 'en-GB', "'"]);

		$this->assertSame($expected, $sql);
	}//end testBuildTranslatableSortSqlMatchesGolden()

	/**
	 * With no usable language code only the fallbacks remain.
	 *
	 * @return void
	 */
	public function testBuildTranslatableSortSqlWithoutCodesKeepsTheFallbacks(): void {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildTranslatableSortSql');
		$sql    = $method->invoke($this->handler(new SqlitePlatform()), 'c', ['', '%%']);

		$this->assertSame(
			"CASE WHEN json_valid(c) THEN CASE WHEN json_type(c) = 'object' THEN COALESCE("
			."(SELECT value FROM json_each(c) LIMIT 1), c) ELSE c END ELSE c END",
			$sql
		);
	}//end testBuildTranslatableSortSqlWithoutCodesKeepsTheFallbacks()
}//end class
