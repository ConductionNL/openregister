<?php

/**
 * Regression: sorting on a date property put empty dates above the newest one.
 *
 * Seen live in pipelinq "All tickets", which asks for `_order[occurredAt]=desc`.
 * `applySorting()` ordered by the bare column, so on PostgreSQL (where NULL is
 * the largest value) every ticket without an occurredAt sat above the newest
 * dated ticket, and on MySQL/MariaDB (where NULL is the smallest) they sat at
 * the bottom. The same list read differently per database.
 *
 * The decision: every object carries OpenRegister's own creation date in
 * `_created`, so an object whose date property is empty sorts as if that
 * property held its creation date. Only `date` and `date-time` string
 * properties get the fallback; every other property and every metadata sort
 * keeps its bare column.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Locks the ORDER BY expressions `applySorting()` hands to the query builder.
 */
class MagicSearchHandlerDateSortTest extends TestCase {

	/**
	 * The real date/timestamp columns of the table the default cases sort.
	 */
	private const DATE_COLUMNS = ['_created', '_updated', 'occurred_at', 'due_date', 'order', 'born_on'];

	private MagicSearchHandler $handler;

	/**
	 * Every addOrderBy() call, rendered as [sort expression, direction].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $orderBy = [];

	protected function setUp(): void {
		$logger = $this->createMock(LoggerInterface::class);

		$this->handler = new MagicSearchHandler(
			db: $this->createMock(IDBConnection::class),
			logger: $logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);

		$this->orderBy = [];
	}//end setUp()

	/**
	 * A QueryBuilder double that quotes identifiers like PostgreSQL and records ORDER BY.
	 *
	 * @return IQueryBuilder The query-builder double.
	 */
	private function makeQueryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getColumnName')->willReturnCallback(
			static fn (string $column, string $alias = ''): string => ($alias === '' ? '' : "\"{$alias}\".") . "\"{$column}\""
		);
		$qb->method('createFunction')->willReturnCallback(
			function (string $call): IQueryFunction {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn($call);
				return $function;
			}
		);
		$qb->method('addOrderBy')->willReturnCallback(
			function ($sort, $direction = null) use ($qb) {
				$this->orderBy[] = [(string) $sort, (string) $direction];
				return $qb;
			}
		);

		return $qb;
	}//end makeQueryBuilder()

	/**
	 * Run applySorting() for one order against a schema and return the recorded ORDER BY.
	 *
	 * @param array<string, string>   $order           The requested order.
	 * @param array<string, mixed>    $properties      The schema's properties.
	 * @param array<int, string>|null $dateTimeColumns The table's real date/timestamp columns, or null when unknown.
	 *
	 * @return array<int, array{0: string, 1: string}> The recorded ORDER BY entries.
	 */
	private function sort(array $order, array $properties, ?array $dateTimeColumns = self::DATE_COLUMNS): array {
		$schema = new Schema();
		$schema->setProperties($properties);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applySorting');
		$method->invoke($this->handler, $this->makeQueryBuilder(), $order, $schema, null, $dateTimeColumns);

		return $this->orderBy;
	}//end sort()

	public function testDateTimePropertyFallsBackToCreatedDescending(): void {
		$result = $this->sort(
			order: ['occurredAt' => 'desc'],
			properties: ['occurredAt' => ['type' => 'string', 'format' => 'date-time']]
		);

		$this->assertSame([['COALESCE("t"."occurred_at", "t"."_created")', 'DESC']], $result);
	}//end testDateTimePropertyFallsBackToCreatedDescending()

	public function testDatePropertyFallsBackToCreatedAscending(): void {
		$result = $this->sort(
			order: ['dueDate' => 'asc'],
			properties: ['dueDate' => ['type' => 'string', 'format' => 'date']]
		);

		$this->assertSame([['COALESCE("t"."due_date", "t"."_created")', 'ASC']], $result);
	}//end testDatePropertyFallsBackToCreatedAscending()

	public function testDatePropertyWithoutExplicitTypeStillFallsBack(): void {
		// MagicMapper maps a property without `type` as a string, so its date
		// format still produces a datetime column.
		$result = $this->sort(
			order: ['occurredAt' => 'DESC'],
			properties: ['occurredAt' => ['format' => 'date-time']]
		);

		$this->assertSame([['COALESCE("t"."occurred_at", "t"."_created")', 'DESC']], $result);
	}//end testDatePropertyWithoutExplicitTypeStillFallsBack()

	public function testReservedWordDatePropertyIsQuoted(): void {
		$result = $this->sort(
			order: ['order' => 'desc'],
			properties: ['order' => ['type' => 'string', 'format' => 'date']]
		);

		$this->assertSame([['COALESCE("t"."order", "t"."_created")', 'DESC']], $result);
	}//end testReservedWordDatePropertyIsQuoted()

	public function testNonDatePropertyKeepsBareColumn(): void {
		$result = $this->sort(
			order: ['title' => 'desc', 'priority' => 'asc'],
			properties: [
				'title'    => ['type' => 'string'],
				'priority' => ['type' => 'integer'],
			]
		);

		// Text sorts lower-cased since 9 October 2026; a number keeps its column.
		$this->assertSame([['LOWER("t"."title")', 'DESC'], ['t.priority', 'ASC']], $result);
	}//end testNonDatePropertyKeepsBareColumn()

	public function testEncryptedDatePropertyKeepsBareColumn(): void {
		// An encrypted property's column holds ciphertext, not a datetime.
		$result = $this->sort(
			order: ['bornOn' => 'asc'],
			properties: ['bornOn' => ['type' => 'string', 'format' => 'date', 'x-openregister-encrypted' => true]]
		);

		$this->assertSame([['t.born_on', 'ASC']], $result);
	}//end testEncryptedDatePropertyKeepsBareColumn()

	public function testMetadataSortsAreUnchanged(): void {
		$result = $this->sort(
			order: ['@self.created' => 'desc', '_updated' => 'asc'],
			properties: []
		);

		$this->assertSame([['t._created', 'DESC'], ['t._updated', 'ASC']], $result);
	}//end testMetadataSortsAreUnchanged()

	public function testDatePropertyOnTextColumnKeepsBareColumn(): void {
		// The property gained its date-time format after its table was created,
		// so its column is still text. PostgreSQL rejects COALESCE(text, timestamp).
		$result = $this->sort(
			order: ['occurredAt' => 'desc'],
			properties: ['occurredAt' => ['type' => 'string', 'format' => 'date-time']],
			dateTimeColumns: ['_created', '_updated']
		);

		$this->assertSame([['t.occurred_at', 'DESC']], $result);
	}//end testDatePropertyOnTextColumnKeepsBareColumn()

	public function testUnknownColumnTypesKeepBareColumn(): void {
		// A caller that does not pass the column types gets the safe default.
		$result = $this->sort(
			order: ['occurredAt' => 'desc'],
			properties: ['occurredAt' => ['type' => 'string', 'format' => 'date-time']],
			dateTimeColumns: null
		);

		$this->assertSame([['t.occurred_at', 'DESC']], $result);
	}//end testUnknownColumnTypesKeepBareColumn()

	public function testNonDatePropertyOnDateColumnKeepsBareColumn(): void {
		// The column list alone is not enough: the schema must also call it a date.
		$result = $this->sort(
			order: ['occurredAt' => 'desc'],
			properties: ['occurredAt' => ['type' => 'string']]
		);

		// Not a date by the schema, so it sorts as text: lower-cased since 9 October 2026.
		$this->assertSame([['LOWER("t"."occurred_at")', 'DESC']], $result);
	}//end testNonDatePropertyOnDateColumnKeepsBareColumn()

	public function testInvalidDirectionFallsBackToAscending(): void {
		$result = $this->sort(
			order: ['occurredAt' => 'sideways'],
			properties: ['occurredAt' => ['type' => 'string', 'format' => 'date-time']]
		);

		$this->assertSame([['COALESCE("t"."occurred_at", "t"."_created")', 'ASC']], $result);
	}//end testInvalidDirectionFallsBackToAscending()
}//end class
