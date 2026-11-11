<?php

/**
 * Characterisation: the `@self`, `tmlo.` and `_relations.` query-key filters of MagicSearchHandler.
 *
 * Pins, branch by branch, the raw-SQL conditions of the `@self` metadata bag
 * and the `tmlo.` sub-field filters, and the relation-field map read from
 * `_relations.<field>` keys, so splitting those builders into named steps
 * cannot change a query.
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

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\ConditionSqlRun;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\UnknownMetadataFieldException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden output of buildMetadataFilterConditionsSql(), buildTmloFilterConditionsSql()
 * and extractRelationFieldFilters().
 */
class MagicSearchHandlerQueryKeyFiltersGoldenTest extends TestCase {

	/**
	 * A handler on PostgreSQL.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handler(): MagicSearchHandler {
		$logger = $this->createMock(LoggerInterface::class);
		$db     = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

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
	 * A connection whose quote() wraps the value, showing its PHP type.
	 *
	 * @return IDBConnection The connection double.
	 */
	private function connection(): IDBConnection {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(
			static fn ($value): string => "'".(string) $value."'".(is_string($value) === true ? '' : ':'.get_debug_type($value))
		);
		return $connection;
	}//end connection()

	/**
	 * One case per branch of the `@self` metadata bag on the raw path.
	 *
	 * @return array<string, array{0: array, 1: bool, 2: list<string>}>
	 */
	public static function metadataQueries(): array {
		return [
			'no bag'              => [[], true, []],
			'bag not an array'    => [['@self' => 'owner'], true, []],
			'empty bag'           => [['@self' => []], true, []],
			'is not null'         => [['@self' => ['owner' => 'IS NOT NULL']], true, ['"_owner" IS NOT NULL']],
			'is null'             => [['@self' => ['owner' => 'IS NULL']], true, ['"_owner" IS NULL']],
			'equality'            => [['@self' => ['owner' => 'jan']], true, ['"_owner" = \'jan\'']],
			'equality integer'    => [['@self' => ['owner' => 5]], true, ['"_owner" = \'5\'']],
			'operator bag'        => [['@self' => ['created' => ['gte' => 'a', 'lt' => 'b']]], true, ['"_created" >= \'a\'', '"_created" < \'b\'']],
			'bare list'           => [['@self' => ['owner' => ['a', 'b']]], true, ['"_owner" IN (\'a\', \'b\')']],
			'mysql quoting'       => [['@self' => ['owner' => 'jan']], false, ['`_owner` = \'jan\'']],
			'several, in order'   => [
				['@self' => ['owner' => 'IS NULL', 'created' => ['lte' => 'z'], 'organisation' => 'o']],
				true,
				['"_owner" IS NULL', '"_created" <= \'z\'', '"_organisation" = \'o\''],
			],
		];
	}//end metadataQueries()

	/**
	 * The `@self` metadata conditions match the golden list.
	 *
	 * @param array        $query      The query.
	 * @param bool         $isPostgres Whether the platform is PostgreSQL.
	 * @param list<string> $expected   The conditions.
	 *
	 * @return void
	 */
	#[DataProvider('metadataQueries')]
	public function testMetadataFilterConditionsMatchGolden(array $query, bool $isPostgres, array $expected): void {
		$this->assertSame($expected, $this->metadata(query: $query, isPostgres: $isPostgres));
	}//end testMetadataFilterConditionsMatchGolden()

	/**
	 * An unknown `@self` key is refused, also after a known one.
	 *
	 * @return void
	 */
	public function testAnUnknownMetadataKeyIsRefused(): void {
		$this->expectException(UnknownMetadataFieldException::class);
		$this->metadata(query: ['@self' => ['owner' => 'x', 'nope' => 'y']], isPostgres: true);
	}//end testAnUnknownMetadataKeyIsRefused()

	/**
	 * Run the raw-path metadata builder.
	 *
	 * @param array $query      The query.
	 * @param bool  $isPostgres Whether the platform is PostgreSQL.
	 *
	 * @return list<string> The conditions.
	 */
	private function metadata(array $query, bool $isPostgres): array {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($value): string => "'".(string) $value."'");

		return (new ReflectionMethod(MagicSearchHandler::class, 'buildMetadataFilterConditionsSql'))->invoke(
			$this->handler(),
			new ConditionSqlRun(query: $query, table: new MagicReadTable(schema: new Schema()), connection: $connection, isPostgres: $isPostgres)
		);
	}//end metadata()

	/**
	 * One case per branch of the `tmlo.` sub-field filters.
	 *
	 * @return array<string, array{0: array, 1: list<string>}>
	 */
	public static function tmloQueries(): array {
		return [
			'none'                 => [['title' => 'x', '_limit' => 5], []],
			'exact'                => [['tmlo.status' => 'actief'], ["_tmlo::jsonb ->> 'status' = 'actief'"]],
			'exact integer'        => [['tmlo.nummer' => 7], ["_tmlo::jsonb ->> 'nummer' = '7'"]],
			'numeric sub-field'    => [['tmlo.5' => 'x'], ["_tmlo::jsonb ->> '5' = 'x'"]],
			'from'                 => [['tmlo.archiefactiedatum[from]' => '2026-01-01'], ["_tmlo::jsonb ->> 'archiefactiedatum' >= '2026-01-01'"]],
			'to'                   => [['tmlo.archiefactiedatum[to]' => '2026-12-31'], ["_tmlo::jsonb ->> 'archiefactiedatum' <= '2026-12-31'"]],
			'null bound'           => [['tmlo.archiefactiedatum[from]' => null], []],
			'integer bound'        => [['tmlo.archiefactiedatum[to]' => 2026], ["_tmlo::jsonb ->> 'archiefactiedatum' <= '2026':int"]],
			'range after exact'    => [
				['tmlo.archiefactiedatum[to]' => 'b', 'tmlo.status' => 's', 'tmlo.archiefactiedatum[from]' => 'a', 'tmlo.x' => 'y'],
				["_tmlo::jsonb ->> 'status' = 's'", "_tmlo::jsonb ->> 'x' = 'y'", "_tmlo::jsonb ->> 'archiefactiedatum' >= 'a'", "_tmlo::jsonb ->> 'archiefactiedatum' <= 'b'"],
			],
		];
	}//end tmloQueries()

	/**
	 * The `tmlo.` conditions match the golden list.
	 *
	 * @param array        $query    The query.
	 * @param list<string> $expected The conditions.
	 *
	 * @return void
	 */
	#[DataProvider('tmloQueries')]
	public function testTmloFilterConditionsMatchGolden(array $query, array $expected): void {
		$conditions = (new ReflectionMethod(MagicSearchHandler::class, 'buildTmloFilterConditionsSql'))->invoke(
			$this->handler(),
			$query,
			$this->connection()
		);

		$this->assertSame($expected, $conditions);
	}//end testTmloFilterConditionsMatchGolden()

	/**
	 * One case per branch of the `_relations.<field>` map.
	 *
	 * @return array<string, array{0: array, 1: array<string, string>}>
	 */
	public static function relationQueries(): array {
		return [
			'none'            => [['title' => 'x', '_relations' => 'y'], []],
			'one'             => [['_relations.author' => 'abc'], ['author' => 'abc']],
			'integer value'   => [['_relations.author' => 12], ['author' => '12']],
			'empty field'     => [['_relations.' => 'abc'], []],
			'list value'      => [['_relations.author' => ['a']], []],
			'null value'      => [['_relations.author' => null], []],
			'empty value'     => [['_relations.author' => ''], []],
			'false value'     => [['_relations.author' => false], []],
			'several'         => [['_relations.b' => '2', 'x' => 'y', '_relations.a' => '1'], ['b' => '2', 'a' => '1']],
		];
	}//end relationQueries()

	/**
	 * The relation-field map matches the golden map.
	 *
	 * @param array                 $query    The query.
	 * @param array<string, string> $expected The map.
	 *
	 * @return void
	 */
	#[DataProvider('relationQueries')]
	public function testRelationFieldFiltersMatchGolden(array $query, array $expected): void {
		$filters = (new ReflectionMethod(MagicSearchHandler::class, 'extractRelationFieldFilters'))->invoke($this->handler(), $query);

		$this->assertSame($expected, $filters);
	}//end testRelationFieldFiltersMatchGolden()
}//end class
