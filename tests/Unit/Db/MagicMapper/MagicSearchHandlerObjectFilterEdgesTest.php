<?php

/**
 * Characterisation: edge cases of the object-field filter paths of MagicSearchHandler.
 *
 * Supplements the raw and QueryBuilder golden tests with the cases they leave
 * out: an empty bare list, an `@`-prefixed key other than `@self`, an
 * underscore key that is not reserved, and the ignored-filter feedback the
 * QueryBuilder path records for unknown and unreadable fields.
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
use OCA\OpenRegister\Db\MagicMapper\TableFilterRun;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\EncryptedFieldFilterException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Edge cases of buildObjectFilterConditionsSql() and applyObjectFilters().
 */
class MagicSearchHandlerObjectFilterEdgesTest extends TestCase {

	private const PROPERTIES = [
		'title'  => ['type' => 'string'],
		'owner'  => ['type' => 'object'],
		'secret' => ['type' => 'object'],
		'sealed' => ['type' => 'string', 'x-openregister-encrypted' => true],
	];

	/**
	 * Predicates captured from the QueryBuilder path.
	 *
	 * @var string[]
	 */
	private array $captured = [];

	/**
	 * A handler on PostgreSQL whose property read rule refuses `secret`.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handler(): MagicSearchHandler {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

		$relatedRows = $this->createMock(RelatedRowQueryApplier::class);
		$relatedRows->method('mayReadProperty')->willReturnCallback(
			static fn (Schema $schema, string $property): bool => $property !== 'secret'
		);

		return new MagicSearchHandler(
			db: $db,
			logger: $this->createMock(LoggerInterface::class),
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: $this->createMock(DateTimeNormalizer::class),
			relatedRows: $relatedRows
		);
	}//end handler()

	/**
	 * The schema under test.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(self::PROPERTIES);
		return $schema;
	}//end schema()

	/**
	 * Run the raw-SQL builder on one query.
	 *
	 * @param array $query The query.
	 *
	 * @return string[] The conditions.
	 */
	private function raw(array $query): array {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($value): string => "'".(string) $value."'");

		return (new ReflectionMethod(MagicSearchHandler::class, 'buildObjectFilterConditionsSql'))->invoke(
			$this->handler(),
			new ConditionSqlRun(query: $query, table: new MagicReadTable(schema: $this->schema()), connection: $connection, isPostgres: true)
		);
	}//end raw()

	/**
	 * A query builder that renders predicates and records them.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'in'] as $operator) {
			$expr->method($operator)->willReturnCallback(
				static fn ($column, $value): string => "{$operator}({$column},{$value})"
			);
		}

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => ':'.json_encode($value));
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					$this->captured[] = (string) $predicate;
				}

				return $qb;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * An empty bare list adds no condition; other keys still filter.
	 *
	 * @return void
	 */
	public function testAnEmptyListAddsNoCondition(): void {
		$this->assertSame([], $this->raw(['title' => []]));
		$this->assertSame(['1=0'], $this->raw(['title' => [], 'title2' => null]));
	}//end testAnEmptyListAddsNoCondition()

	/**
	 * Every `@`-prefixed and every underscore-prefixed key is skipped on the raw path.
	 *
	 * @return void
	 */
	public function testPrefixedKeysAreSkippedOnTheRawPath(): void {
		$this->assertSame(['"title" = \'y\''], $this->raw(['@other' => 'x', '_custom' => 'x', '_limit' => 5, 'title' => 'y']));
	}//end testPrefixedKeysAreSkippedOnTheRawPath()

	/**
	 * Every unknown key matches nothing on the raw path, in its place in the order.
	 *
	 * @return void
	 */
	public function testAnUnknownKeyMatchesNothingInOrder(): void {
		$this->assertSame(['"title" = \'a\'', '1=0', '1=0'], $this->raw(['title' => 'a', 'nope' => 'x', 'title ' => 'b']));
	}//end testAnUnknownKeyMatchesNothingInOrder()

	/**
	 * The QueryBuilder path records unknown and unreadable fields, in order,
	 * and adds an impossible condition for each.
	 *
	 * @return void
	 */
	public function testTheQueryBuilderPathRecordsIgnoredFilters(): void {
		$handler = $this->handler();
		$this->captured = [];
		(new ReflectionMethod(MagicSearchHandler::class, 'applyObjectFilters'))->invoke(
			$handler,
			new TableFilterRun(qb: $this->queryBuilder(), query: [], table: new MagicReadTable(schema: $this->schema())),
			['nope' => 1, 'secret.name' => 'x', 'title' => 'a', 'owner.zip.code' => 'z']
		);

		$this->assertSame(['secret.name'], array_slice($handler->getIgnoredFilters(), 0, 1));
		$this->assertSame(['secret.name', 'nope'], $handler->getIgnoredFilters());
		$this->assertSame('1 = 0', $this->captured[0]);
		$this->assertContains('1 = 0', array_slice($this->captured, 1));
	}//end testTheQueryBuilderPathRecordsIgnoredFilters()

	/**
	 * An encrypted field is refused before any condition is added, on the QueryBuilder path.
	 *
	 * @return void
	 */
	public function testAnEncryptedFieldIsRefusedBeforeAnyCondition(): void {
		$this->captured = [];
		try {
			(new ReflectionMethod(MagicSearchHandler::class, 'applyObjectFilters'))->invoke(
				$this->handler(),
				new TableFilterRun(qb: $this->queryBuilder(), query: [], table: new MagicReadTable(schema: $this->schema())),
				['title' => 'a', 'sealed' => 'x']
			);
			$this->fail('An encrypted field filter must be refused.');
		} catch (EncryptedFieldFilterException $e) {
			$this->assertSame([], $this->captured);
		}
	}//end testAnEncryptedFieldIsRefusedBeforeAnyCondition()
}//end class
