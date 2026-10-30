<?php

/**
 * Referential integrity: a reference that cites a row by a key property.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCP\DB\IPreparedStatement;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A ground row (`code`) cited by code from an assessment's array property.
 *
 * The fixture is neutral: a `label` schema whose rows carry a `code`, and a
 * `note` schema whose `labels` property lists codes.
 *
 * @covers \OCA\OpenRegister\Service\Object\ReferentialIntegrityService
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Dto\DeletionAnalysis
 * @uses \OCA\OpenRegister\Service\Object\ReferenceKey
 */
class ReferentialIntegrityKeyReferenceTest extends TestCase {

	/**
	 * The database connection mock.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	/**
	 * The schema mapper mock.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * The service under test.
	 *
	 * @var ReferentialIntegrityService
	 */
	private ReferentialIntegrityService $service;

	/**
	 * The mapper mock: it names the citing table and its columns.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $mapper;

	/**
	 * The SQL the citing-table query was prepared with.
	 *
	 * @var string
	 */
	private string $sql = '';

	/**
	 * The platform the connection reports; MySQL unless a test says otherwise.
	 *
	 * @var AbstractPlatform
	 */
	private AbstractPlatform $reported;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		// The citing table, as the mapper names it and as the connection prefixes it.
		$this->mapper = $this->createMock(MagicMapper::class);
		$this->mapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_7_2');
		$queryBuilder = $this->createMock(IQueryBuilder::class);
		// Like Nextcloud's own: the prefixed name comes back ALREADY quoted for the platform.
		$queryBuilder->method('getTableName')->willReturnCallback(fn (string $table): string => $this->reported->quoteIdentifier('oc_' . $table));
		$this->db->method('getQueryBuilder')->willReturn($queryBuilder);
		$this->reported = new MySQLPlatform();
		$this->db->method('getDatabasePlatform')->willReturnCallback(fn (): AbstractPlatform => $this->reported);

		$this->service = new ReferentialIntegrityService(
			schemaMapper: $this->schemaMapper,
			registerMapper: $this->createMock(RegisterMapper::class),
			objectEntityMapper: $this->mapper,
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			logger: $this->createMock(LoggerInterface::class),
			db: $this->db,
			cacheFactory: $cacheFactory,
			archivalGuard: $this->createMock(ArchivalRetentionGuard::class),
		);
	}//end setUp()

	/**
	 * Index the two fixture schemas, the citing one with the given property.
	 *
	 * @param array<string, mixed> $labelsProperty The citing property's definition.
	 * @param bool $withRegister Whether the citing schema lives in a register.
	 * @param array<string, mixed>|null $columns The citing table's column map; null gives `labels` its column.
	 *
	 * @return void
	 */
	private function schemas(array $labelsProperty, bool $withRegister = true, ?array $columns = null): void {
		$label = new Schema();
		$label->setId(1);
		$label->setSlug('label');
		$label->setUuid('uuid-schema-1');
		$label->setProperties(['code' => ['type' => 'string']]);

		$note = new Schema();
		$note->setId(2);
		$note->setSlug('note');
		$note->setUuid('uuid-schema-2');
		$note->setProperties(['labels' => $labelsProperty]);
		$note->setRequired([]);

		$this->schemaMapper->method('findAll')->willReturn([$label, $note]);
		// The citing table's column for its property, as the table was built (a collision suffix included).
		$this->mapper->method('buildTableColumnsFromSchema')->willReturn($columns ?? ['labels' => ['name' => 'labels_1', 'type' => 'json']]);

		$register = new Register();
		$register->setId(7);

		$this->setProperty(name: 'schemaCache', value: ['1' => $label, '2' => $note]);
		$this->setProperty(name: 'schemaRegisterMap', value: $withRegister === true ? ['2' => $register] : []);
	}//end schemas()

	/**
	 * Set a private property on the service.
	 *
	 * @param string $name The property.
	 * @param mixed $value The value.
	 *
	 * @return void
	 */
	private function setProperty(string $name, mixed $value): void {
		$property = new \ReflectionProperty(ReferentialIntegrityService::class, $name);
		$property->setValue($this->service, $value);
	}//end setProperty()

	/**
	 * A label row with a code.
	 *
	 * @param string $code The code.
	 *
	 * @return ObjectEntity The row.
	 */
	private function label(string $code): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('label-uuid');
		$entity->setSchema('1');
		$entity->setObject(['code' => $code]);

		return $entity;
	}//end label()

	/**
	 * The citing-table query answers with these rows, and records the value it was asked for.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param array<int, mixed> $params Receives the bound parameters.
	 *
	 * @return void
	 */
	private function citingRows(array $rows, array &$params): void {
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('execute')->willReturnCallback(
			function (?array $bound = null) use (&$params) {
				$params = (array)$bound;
				return $this->createMock(\OCP\DB\IResult::class);
			}
		);
		$statement->method('fetchAll')->willReturn($rows);
		$this->db->method('prepare')->willReturnCallback(
			function (string $sql) use ($statement) {
				$this->sql = $sql;

				return $statement;
			}
		);
	}//end citingRows()

	/**
	 * A row cited by its code may not be deleted; the citing row is named.
	 *
	 * @return void
	 */
	public function testCitedByKeyIsRestricted(): void {
		$this->schemas(labelsProperty: ['type' => 'array', 'items' => ['type' => 'string'], 'referenceSchema' => 'label', 'referenceKey' => 'code', 'onDelete' => 'RESTRICT']);
		$params = [];
		$this->citingRows(rows: [['_uuid' => 'note-1', '_register' => '7', '_schema' => '2', '_deleted' => null, '_prop' => '["A1"]']], params: $params);

		$analysis = $this->service->canDelete($this->label(code: 'A1'));

		self::assertFalse($analysis->deletable);
		self::assertSame('note-1', $analysis->blockers[0]['objectUuid']);
		self::assertSame('RESTRICT', $analysis->blockers[0]['action']);
		self::assertSame(['A1'], $params, 'the citing table must be searched for the CODE, not the uuid');
	}//end testCitedByKeyIsRestricted()

	/**
	 * A row nothing cites by its code may be deleted.
	 *
	 * @return void
	 */
	public function testUncitedKeyIsDeletable(): void {
		$this->schemas(labelsProperty: ['type' => 'array', 'items' => ['type' => 'string', 'referenceSchema' => 'label', 'referenceKey' => 'code'], 'onDelete' => 'RESTRICT']);
		$params = [];
		$this->citingRows(rows: [], params: $params);

		self::assertTrue($this->service->canDelete($this->label(code: 'B2'))->deletable);
	}//end testUncitedKeyIsDeletable()

	/**
	 * A citation that cannot be read refuses the delete as unverifiable.
	 *
	 * @return void
	 */
	public function testUnreadableCitationFailsClosed(): void {
		$this->schemas(labelsProperty: ['type' => 'array', 'referenceSchema' => 'label', 'referenceKey' => 'code', 'onDelete' => 'RESTRICT']);
		$this->db->method('prepare')->willThrowException(new RuntimeException('connection lost'));
		$this->db->method('tableExists')->willReturn(true);

		$analysis = $this->service->canDelete($this->label(code: 'A1'));

		self::assertFalse($analysis->deletable);
		self::assertSame('unverifiable', $analysis->blockers[0]['reason']);
	}//end testUnreadableCitationFailsClosed()

	/**
	 * A citing table that does not exist yet means nothing cites the row.
	 *
	 * @return void
	 */
	public function testMissingCitingTableMeansNoCitation(): void {
		$this->schemas(labelsProperty: ['type' => 'array', 'referenceSchema' => 'label', 'referenceKey' => 'code', 'onDelete' => 'RESTRICT']);
		$this->db->method('prepare')->willThrowException(new RuntimeException('no such table'));
		$this->db->method('tableExists')->willReturn(false);

		self::assertTrue($this->service->canDelete($this->label(code: 'A1'))->deletable);
	}//end testMissingCitingTableMeansNoCitation()

	/**
	 * A citing schema outside any register cannot be checked, so the delete is refused.
	 *
	 * @return void
	 */
	public function testCitingSchemaWithoutRegisterFailsClosed(): void {
		$this->schemas(labelsProperty: ['type' => 'array', 'referenceSchema' => 'label', 'referenceKey' => 'code', 'onDelete' => 'RESTRICT'], withRegister: false);

		$analysis = $this->service->canDelete($this->label(code: 'A1'));

		self::assertSame('unverifiable', $analysis->blockers[0]['reason']);
	}//end testCitingSchemaWithoutRegisterFailsClosed()

	/**
	 * A row without a value for the key cannot be cited by it.
	 *
	 * @return void
	 */
	public function testRowWithoutKeyIsDeletable(): void {
		$this->schemas(labelsProperty: ['type' => 'array', 'referenceSchema' => 'label', 'referenceKey' => 'code', 'onDelete' => 'RESTRICT']);
		$this->db->expects(self::never())->method('prepare');

		self::assertTrue($this->service->canDelete($this->label(code: ''))->deletable);
	}//end testRowWithoutKeyIsDeletable()

	/**
	 * A key reference with an action other than RESTRICT is not indexed at all.
	 *
	 * @return void
	 */
	public function testKeyReferenceOnlyRestricts(): void {
		$this->schemas(labelsProperty: ['type' => 'array', 'referenceSchema' => 'label', 'referenceKey' => 'code', 'onDelete' => 'CASCADE']);

		self::assertFalse($this->service->hasIncomingOnDeleteReferences('1'));
	}//end testKeyReferenceOnlyRestricts()

	/**
	 * On MySQL and MariaDB a key reference is searched by comparing the column, never a quoted string.
	 *
	 * A double-quoted identifier is a string literal there (no ANSI_QUOTES): `"code" = ?` is
	 * `'code' = ?`, false for every row, so the delete went through. The identifier is
	 * quoted by the platform (backticks), the column is the one the schema's table was
	 * built with, and the table carries the instance's prefix.
	 *
	 * @return void
	 */
	public function testAScalarKeyIsComparedAsAColumnOnMySql(): void {
		$this->schemas(labelsProperty: ['type' => 'string', 'referenceSchema' => 'label', 'referenceKey' => 'code', 'onDelete' => 'RESTRICT']);
		$params = [];
		$this->citingRows(rows: [['_uuid' => 'note-1', '_register' => '7', '_schema' => '2', '_deleted' => null, '_prop' => 'A1']], params: $params);

		$analysis = $this->service->canDelete($this->label(code: 'A1'));

		self::assertFalse($analysis->deletable);
		self::assertStringContainsString('`labels_1` = ?', $this->sql);
		self::assertStringNotContainsString('"', $this->sql, 'No double-quoted identifier: MySQL reads it as a string.');
		self::assertStringContainsString('FROM `oc_openregister_table_7_2`', $this->sql);
		self::assertStringNotContainsString('``', $this->sql, 'The table must not be quoted twice: that names a table that does not exist and the lookup answers "no referrers".');
		self::assertSame(['A1'], $params);
	}//end testAScalarKeyIsComparedAsAColumnOnMySql()

	/**
	 * On PostgreSQL the same query quotes with double quotes, the platform's own way.
	 *
	 * @return void
	 */
	public function testAScalarKeyIsComparedAsAColumnOnPostgres(): void {
		$this->reported = new PostgreSQLPlatform();
		$this->schemas(labelsProperty: ['type' => 'string', 'referenceSchema' => 'label', 'referenceKey' => 'code', 'onDelete' => 'RESTRICT']);
		$params = [];
		$this->citingRows(rows: [], params: $params);

		self::assertTrue($this->service->canDelete($this->label(code: 'A1'))->deletable);
		self::assertStringContainsString('"labels_1" = ?', $this->sql);
		self::assertStringContainsString('FROM "oc_openregister_table_7_2"', $this->sql);
	}//end testAScalarKeyIsComparedAsAColumnOnPostgres()

	/**
	 * A property the citing table has no column for cannot be checked, so the delete is refused.
	 *
	 * @return void
	 */
	public function testAPropertyWithoutAColumnFailsClosed(): void {
		$this->schemas(labelsProperty: ['type' => 'array', 'referenceSchema' => 'label', 'referenceKey' => 'code', 'onDelete' => 'RESTRICT'], columns: []);
		$this->db->expects(self::never())->method('prepare');
		$this->db->method('tableExists')->willReturn(true);

		self::assertSame('unverifiable', $this->service->canDelete($this->label(code: 'A1'))->blockers[0]['reason']);
	}//end testAPropertyWithoutAColumnFailsClosed()
}//end class
