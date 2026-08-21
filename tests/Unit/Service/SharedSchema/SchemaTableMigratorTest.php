<?php

/**
 * Unit tests for the shared-schema row move (#2689).
 *
 * The column-mapping rules are asserted directly, and the statement they produce
 * is then EXECUTED against a real in-memory SQLite database. A generated SQL
 * string that is only string-compared proves the generator agrees with the test
 * author, not that the database accepts it — and this statement moves object
 * rows, so "it parses and moves exactly the mapped columns" is the property that
 * matters.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\SharedSchema
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

namespace OCA\OpenRegister\Tests\Unit\Service\SharedSchema;

use OCA\OpenRegister\Service\SharedSchema\SchemaTableMigrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Locks the column mapping and the copy statement it feeds.
 */
class SchemaTableMigratorTest extends TestCase {

	/**
	 * The columns planix's `timeEntry` table carries.
	 *
	 * @var string[]
	 */
	private const PLANIX_COLUMNS = [
		'_id',
		'_uuid',
		'_register',
		'_schema',
		'_uri',
		'description',
		'date',
		'duration',
		'employee',
		'approved',
	];

	/**
	 * The columns pipelinq's own `timeEntry` definition would materialise.
	 *
	 * @var string[]
	 */
	private const PIPELINQ_COLUMNS = [
		'_id',
		'_uuid',
		'_register',
		'_schema',
		'_uri',
		'hours',
		'billing_category',
		'client',
	];

	/**
	 * Columns present in both tables move; source-only columns are reported.
	 *
	 * This is the shape of the real repair: pipelinq's restored definition has
	 * columns planix's overwrite had removed, and lacks the ones that belonged to
	 * planix. Those planix-only columns must be NAMED, never dropped in silence.
	 *
	 * @return void
	 */
	public function testMapsSharedColumnsAndReportsTheRest(): void {
		$plan = SchemaTableMigrator::planColumnMapping(
			sourceColumns: self::PLANIX_COLUMNS,
			targetColumns: self::PIPELINQ_COLUMNS
		);

		$this->assertSame(['_register', '_schema', '_uri', '_uuid'], $plan['mapped']);
		$this->assertSame(
			['approved', 'date', 'description', 'duration', 'employee'],
			$plan['unmapped'],
			'every source column without a destination must be reported'
		);

	}//end testMapsSharedColumnsAndReportsTheRest()

	/**
	 * `_id` never moves.
	 *
	 * It is an autoincrement primary key. Copying the values verbatim would leave
	 * the target's sequence behind the highest copied id, so the next insert into
	 * the repaired table would collide. It must also not be reported as unmapped,
	 * or `--strict` would refuse every otherwise healthy split.
	 *
	 * @return void
	 */
	public function testPrimaryKeyIsNeitherCopiedNorReported(): void {
		$plan = SchemaTableMigrator::planColumnMapping(
			sourceColumns: ['_id', '_uuid'],
			targetColumns: ['_id', '_uuid']
		);

		$this->assertSame(['_uuid'], $plan['mapped']);
		$this->assertSame([], $plan['unmapped']);

	}//end testPrimaryKeyIsNeitherCopiedNorReported()

	/**
	 * A clone-path split maps every column, so nothing is left behind.
	 *
	 * @return void
	 */
	public function testIdenticalShapesLeaveNothingUnmapped(): void {
		$plan = SchemaTableMigrator::planColumnMapping(
			sourceColumns: self::PLANIX_COLUMNS,
			targetColumns: self::PLANIX_COLUMNS
		);

		$this->assertSame([], $plan['unmapped']);
		$this->assertNotContains('_id', $plan['mapped']);
		$this->assertCount((count(self::PLANIX_COLUMNS) - 1), $plan['mapped']);

	}//end testIdenticalShapesLeaveNothingUnmapped()

	/**
	 * Column matching is case-insensitive across the two introspection results.
	 *
	 * `information_schema` folds identifier case differently per platform. If the
	 * comparison were case-sensitive every column would read as unmapped, and
	 * `--strict` would refuse every split on the affected platform.
	 *
	 * @return void
	 */
	public function testMatchingIsCaseInsensitive(): void {
		$plan = SchemaTableMigrator::planColumnMapping(
			sourceColumns: ['_uuid', 'billingCategory'],
			targetColumns: ['_UUID', 'BILLINGCATEGORY']
		);

		$this->assertSame(['_uuid', 'billingCategory'], $plan['mapped']);
		$this->assertSame([], $plan['unmapped']);

	}//end testMatchingIsCaseInsensitive()

	/**
	 * A target column with no source counterpart is not an error.
	 *
	 * The restored definition legitimately adds back columns the overwrite had
	 * removed; they simply have no rows to carry and take their default.
	 *
	 * @return void
	 */
	public function testTargetOnlyColumnsAreNotReported(): void {
		$plan = SchemaTableMigrator::planColumnMapping(
			sourceColumns: ['_uuid'],
			targetColumns: ['_uuid', 'billing_category', 'client']
		);

		$this->assertSame(['_uuid'], $plan['mapped']);
		$this->assertSame([], $plan['unmapped']);

	}//end testTargetOnlyColumnsAreNotReported()

	/**
	 * The copy statement quotes every identifier and selects only mapped columns.
	 *
	 * @return void
	 */
	public function testCopyStatementQuotesIdentifiers(): void {
		$sql = SchemaTableMigrator::buildCopySql(
			sourceTable: 'oc_openregister_table_16_161',
			targetTable: 'oc_openregister_table_16_9465',
			columns: ['_uuid', '_schema'],
			quote: '"'
		);

		$this->assertSame(
			'INSERT INTO "oc_openregister_table_16_9465" ("_uuid", "_schema") '
			. 'SELECT "_uuid", "_schema" FROM "oc_openregister_table_16_161"',
			$sql
		);

	}//end testCopyStatementQuotesIdentifiers()

	/**
	 * MySQL and MariaDB get backticks.
	 *
	 * @return void
	 */
	public function testCopyStatementHonoursTheBacktickDialect(): void {
		$sql = SchemaTableMigrator::buildCopySql(
			sourceTable: 'oc_openregister_table_16_161',
			targetTable: 'oc_openregister_table_16_9465',
			columns: ['_uuid'],
			quote: '`'
		);

		$this->assertStringContainsString('`oc_openregister_table_16_9465`', $sql);
		$this->assertStringContainsString('`_uuid`', $sql);

	}//end testCopyStatementHonoursTheBacktickDialect()

	/**
	 * An identifier that is not a plain SQL name is refused, not interpolated.
	 *
	 * Table and column names cannot be bound as parameters, so this guard is the
	 * only thing between an introspection result and a raw statement.
	 *
	 * @return void
	 */
	public function testUnsafeIdentifierIsRefused(): void {
		$this->expectException(RuntimeException::class);
		SchemaTableMigrator::buildCopySql(
			sourceTable: 'oc_openregister_table_16_161',
			targetTable: 'x"; DROP TABLE users; --',
			columns: ['_uuid'],
			quote: '"'
		);

	}//end testUnsafeIdentifierIsRefused()

	/**
	 * An empty mapping is refused rather than producing invalid SQL.
	 *
	 * @return void
	 */
	public function testEmptyMappingIsRefused(): void {
		$this->expectException(RuntimeException::class);
		SchemaTableMigrator::buildCopySql(
			sourceTable: 'oc_openregister_table_16_161',
			targetTable: 'oc_openregister_table_16_9465',
			columns: [],
			quote: '"'
		);

	}//end testEmptyMappingIsRefused()

	/**
	 * END-TO-END: the generated statement really moves the mapped rows.
	 *
	 * Executed against an in-memory SQLite database built to the shape of the
	 * observed case: a source table holding planix's columns and two rows, and a
	 * target table built from pipelinq's restored definition. After the copy the
	 * shared columns must have carried over, the pipelinq-only columns must be
	 * empty (there was nothing to carry), and the planix-only columns must still
	 * exist in the untouched source so the operator can recover them.
	 *
	 * @return void
	 */
	public function testGeneratedStatementMovesRowsOnARealDatabase(): void {
		$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

		$pdo->exec(
			'CREATE TABLE oc_openregister_table_16_161 (
				_id INTEGER PRIMARY KEY AUTOINCREMENT,
				_uuid TEXT, _register TEXT, _schema TEXT, _uri TEXT,
				description TEXT, date TEXT, duration REAL, employee TEXT, approved INTEGER)'
		);
		$pdo->exec(
			'CREATE TABLE oc_openregister_table_16_9465 (
				_id INTEGER PRIMARY KEY AUTOINCREMENT,
				_uuid TEXT, _register TEXT, _schema TEXT, _uri TEXT,
				hours REAL, billing_category TEXT, client TEXT)'
		);

		$pdo->exec(
			"INSERT INTO oc_openregister_table_16_161
				(_uuid, _register, _schema, _uri, description, date, duration, employee, approved)
			 VALUES
				('uuid-a', '16', '161', '/api/objects/16/161/uuid-a', 'Sprint work', '2026-08-01', 3.5, 'ruben', 1),
				('uuid-b', '16', '161', '/api/objects/16/161/uuid-b', 'Review',      '2026-08-02', 1.0, 'ruben', 0)"
		);

		$plan = SchemaTableMigrator::planColumnMapping(
			sourceColumns: $this->columnsOf(pdo: $pdo, table: 'oc_openregister_table_16_161'),
			targetColumns: $this->columnsOf(pdo: $pdo, table: 'oc_openregister_table_16_9465')
		);

		$this->assertSame(
			['approved', 'date', 'description', 'duration', 'employee'],
			$plan['unmapped'],
			'guard: the fixture must actually exercise unmapped columns'
		);

		$pdo->exec(
			SchemaTableMigrator::buildCopySql(
				sourceTable: 'oc_openregister_table_16_161',
				targetTable: 'oc_openregister_table_16_9465',
				columns: $plan['mapped'],
				quote: '"'
			)
		);

		$moved = $pdo->query(
			'SELECT _id, _uuid, _schema, _uri, hours FROM oc_openregister_table_16_9465 ORDER BY _uuid'
		)->fetchAll(PDO::FETCH_ASSOC);

		$this->assertCount(2, $moved, 'both rows must arrive');
		$this->assertSame(['uuid-a', 'uuid-b'], array_column($moved, '_uuid'));
		$this->assertSame([1, 2], array_map('intval', array_column($moved, '_id')), '_id is reassigned, not copied');
		$this->assertSame([null, null], array_column($moved, 'hours'), 'a target-only column has nothing to carry');

		// The rows still carry the OLD schema id until the restamp runs; the
		// migrator issues that UPDATE, and this asserts it is genuinely needed.
		$this->assertSame(['161', '161'], array_column($moved, '_schema'));
		$pdo->exec('UPDATE oc_openregister_table_16_9465 SET _schema = \'9465\' WHERE _schema = \'161\'');
		$pdo->exec(
			'UPDATE oc_openregister_table_16_9465 SET _uri = REPLACE(_uri, \'/16/161/\', \'/16/9465/\')'
		);

		$restamped = $pdo->query(
			'SELECT _schema, _uri FROM oc_openregister_table_16_9465 ORDER BY _uuid'
		)->fetchAll(PDO::FETCH_ASSOC);

		$this->assertSame(['9465', '9465'], array_column($restamped, '_schema'));
		$this->assertSame(
			['/api/objects/16/9465/uuid-a', '/api/objects/16/9465/uuid-b'],
			array_column($restamped, '_uri'),
			'the denormalised uri must follow the new schema id'
		);

		$survivors = $pdo->query(
			'SELECT COUNT(*) FROM oc_openregister_table_16_161 WHERE description IS NOT NULL'
		)->fetchColumn();

		$this->assertSame(2, (int)$survivors, 'the unmapped columns must remain recoverable in the source table');

	}//end testGeneratedStatementMovesRowsOnARealDatabase()

	/**
	 * Read a SQLite table's column names.
	 *
	 * @param PDO    $pdo   The connection.
	 * @param string $table The table name.
	 *
	 * @return string[] The column names.
	 */
	private function columnsOf(PDO $pdo, string $table): array {
		$rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);

		return array_map(static fn (array $row): string => (string)$row['name'], $rows);

	}//end columnsOf()
}//end class
