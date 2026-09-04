<?php

declare(strict_types=1);

/**
 * Unit tests for the raw-append side of MagicBulkHandler.
 *
 * Two things are pinned here. First, that an expiry in the metadata block
 * lands in `_expires`, and that a bare property called `expires` does NOT:
 * a schema may declare that property, and mapping it would silently
 * schedule the row for deletion. Second, that purgeExpired() deletes by
 * register, schema and a passed `_expires`, and reports the count.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 */

namespace Unit\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class MagicBulkHandlerRawAppendTest extends TestCase {

	private IDBConnection&MockObject $db;

	private MagicBulkHandler $handler;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		// A REAL normaliser: the point of the expiry test is the stored format.
		$this->handler = new MagicBulkHandler(
			$this->db,
			$logger,
			$this->createMock(IEventDispatcher::class),
			new DateTimeNormalizer($logger),
			$this->createMock(IConfig::class)
		);

		$this->register = new Register();
		$this->register->setId(11);

		$this->schema = new Schema();
		$this->schema->setId(22);
	}

	/**
	 * Run the private row preparation on one object.
	 */
	private function prepare(array $object): array {
		$method = new ReflectionMethod(MagicBulkHandler::class, 'prepareObjectsForDynamicTable');
		$method->setAccessible(true);

		$prepared = $method->invoke($this->handler, [$object], $this->register, $this->schema);

		return $prepared[0];
	}

	public function testAnExpiryInTheMetadataBlockLandsInTheExpiresColumn(): void {
		// Midday, so the calendar date survives any server timezone.
		$row = $this->prepare([
			'@self' => ['uuid' => 'u-1', 'expires' => '2026-01-01T12:00:00+00:00'],
			'name' => 'page_view',
		]);

		$this->assertSame('u-1', $row['_uuid']);
		$this->assertArrayHasKey('_expires', $row);
		$this->assertMatchesRegularExpression('/^2026-01-01 \d{2}:\d{2}:\d{2}$/', $row['_expires']);
		$this->assertSame('page_view', $row['name']);
	}

	public function testAFlatMetadataRowAlsoCarriesItsExpiry(): void {
		$row = $this->prepare([
			'uuid' => 'u-2',
			'expires' => new \DateTimeImmutable('2027-06-15 12:00:00', new \DateTimeZone('UTC')),
			'object' => ['name' => 'scroll'],
		]);

		$this->assertSame('u-2', $row['_uuid']);
		$this->assertMatchesRegularExpression('/^2027-06-15 \d{2}:\d{2}:\d{2}$/', $row['_expires']);
		$this->assertSame('scroll', $row['name']);
	}

	public function testABarePropertyCalledExpiresStaysAProperty(): void {
		$row = $this->prepare(['uuid' => 'u-3', 'expires' => '2026-01-01T12:00:00+00:00', 'name' => 'licence']);

		$this->assertArrayNotHasKey('_expires', $row, 'A schema property named expires must not schedule the row for purge.');
		$this->assertSame('2026-01-01T12:00:00+00:00', $row['expires']);
	}

	public function testNoExpiryMeansNoExpiresKeyAtAll(): void {
		// Absent, not null: an upsert of an existing row must not null out its expiry.
		$row = $this->prepare(['@self' => ['uuid' => 'u-4'], 'name' => 'page_view']);

		$this->assertArrayNotHasKey('_expires', $row);
	}

	public function testPurgeExpiredDeletesByRegisterSchemaAndPassedExpiry(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(fn (string $col, string $param): string => "$col = $param");
		$expr->expects($this->once())->method('isNotNull')->with('_expires')->willReturn('_expires IS NOT NULL');
		$expr->expects($this->once())->method('lt')->with('_expires', 'NOW()')->willReturn('_expires < NOW()');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(fn (mixed $value): string => ':p' . $value);
		$qb->method('createFunction')->willReturnCallback(fn (string $fn): string => $fn);
		$qb->expects($this->once())->method('delete')->with('openregister_table_11_22')->willReturnSelf();
		$qb->expects($this->once())->method('where')->with('_register = :p11')->willReturnSelf();

		$andWheres = [];
		$qb->expects($this->exactly(3))
			->method('andWhere')
			->willReturnCallback(function (string $clause) use (&$andWheres, $qb): IQueryBuilder {
				$andWheres[] = $clause;
				return $qb;
			});
		$qb->expects($this->once())->method('executeStatement')->willReturn(4);

		$this->db->method('getQueryBuilder')->willReturn($qb);

		$purged = $this->handler->purgeExpired($this->register, $this->schema, 'openregister_table_11_22');

		$this->assertSame(4, $purged);
		$this->assertSame(['_schema = :p22', '_expires IS NOT NULL', '_expires < NOW()'], $andWheres);
	}
}
