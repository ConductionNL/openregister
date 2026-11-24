<?php

/**
 * MagicTableIndexer creates the composite indexes a schema declares in x-openregister-indexes.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 *
 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-an-address-is-found-by-postcode-and-house-number-req-bag-002
 */
class MagicTableCompositeIndexTest extends TestCase {

	/**
	 * Statements the connection ran (failed ones included).
	 *
	 * @var array<int, string>
	 */
	private array $statements = [];

	/**
	 * An indexer whose statements throw when they contain one of the given fragments.
	 *
	 * @param AbstractPlatform   $platform The platform.
	 * @param array<int, string> $failing  Statement fragments that make executeStatement throw.
	 *
	 * @return MagicTableIndexer
	 */
	private function indexer(AbstractPlatform $platform, array $failing=[]): MagicTableIndexer {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($failing): int {
				$this->statements[] = $sql;
				foreach ($failing as $fragment) {
					if (str_contains($sql, $fragment) === true) {
						throw new RuntimeException('cannot create ' . $fragment);
					}
				}

				return 0;
			}
		);
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(0);
		$prepared = $this->createMock(IPreparedStatement::class);
		$prepared->method('execute')->willReturn($result);
		$db->method('prepare')->willReturn($prepared);

		$logger = $this->createMock(LoggerInterface::class);
		return new MagicTableIndexer($db, $logger, new MagicPgTrgmProbe($db, $logger));
	}//end indexer()

	/**
	 * A schema with the address properties and the given index declaration.
	 *
	 * @param mixed $indexes The x-openregister-indexes value, or null for none.
	 *
	 * @return Schema
	 */
	private function schema(mixed $indexes): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(
			[
				'postcode' => ['type' => 'string'],
				'huisnummer' => ['type' => 'integer'],
				'huisletter' => ['type' => 'string'],
			]
		);
		$configuration = [];
		if ($indexes !== null) {
			$configuration[Schema::INDEXES_ANNOTATION] = $indexes;
		}

		$schema->method('getConfiguration')->willReturn($configuration);
		return $schema;
	}//end schema()

	/**
	 * The composite statements the indexer ran.
	 *
	 * @return array<int, string>
	 */
	private function compositeStatements(): array {
		return array_values(array_filter($this->statements, static fn (string $sql): bool => str_contains($sql, '_cmp_idx') === true));
	}//end compositeStatements()

	/**
	 * On PostgreSQL a declared pair gets one index over both columns, in order.
	 *
	 * @return void
	 */
	public function testPostgresIndexesADeclaredPairTogether(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');

		$this->indexer(new PostgreSQLPlatform())->create($run, $this->schema([['postcode', 'huisnummer']]));

		$name = 't_' . substr(md5('postcode,huisnummer'), 0, 12) . '_cmp_idx';
		$this->assertSame(["CREATE INDEX IF NOT EXISTS {$name} ON oc_t (\"postcode\", \"huisnummer\")"], $this->compositeStatements());
		$this->assertSame(['postcode+huisnummer'], $run->compositeIndexes);
	}//end testPostgresIndexesADeclaredPairTogether()

	/**
	 * On MySQL the statement has no IF NOT EXISTS, which it does not support.
	 *
	 * @return void
	 */
	public function testMysqlIndexesWithoutIfNotExists(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');

		$this->indexer(new MySQLPlatform())->create($run, $this->schema([['postcode', 'huisnummer', 'huisletter']]));

		$name = 't_' . substr(md5('postcode,huisnummer,huisletter'), 0, 12) . '_cmp_idx';
		$this->assertSame(["CREATE INDEX {$name} ON oc_t (`postcode`, `huisnummer`, `huisletter`)"], $this->compositeStatements());
		$this->assertSame(['postcode+huisnummer+huisletter'], $run->compositeIndexes);
	}//end testMysqlIndexesWithoutIfNotExists()

	/**
	 * An entry naming an undefined property, a single property or no list at all makes no index.
	 *
	 * @return void
	 */
	public function testEntriesWithoutTwoDefinedPropertiesAreSkipped(): void {
		$declared = [['postcode', 'straat'], ['postcode'], 'postcode', [['postcode']], ['huisnummer', 'huisletter']];

		$this->assertSame([['huisnummer', 'huisletter']], MagicTableIndexer::compositeIndexColumns($this->schema($declared)));
		$this->assertSame([], MagicTableIndexer::compositeIndexColumns($this->schema('postcode')));
		$this->assertSame([], MagicTableIndexer::compositeIndexColumns($this->schema(null)));
	}//end testEntriesWithoutTwoDefinedPropertiesAreSkipped()

	/**
	 * A failing composite statement is not reported and the next one is still made.
	 *
	 * @return void
	 */
	public function testAFailingCompositeIndexDoesNotStopTheNext(): void {
		$run   = new MagicTableIndexRun('t', 'oc_t');
		$first = 't_' . substr(md5('postcode,huisnummer'), 0, 12) . '_cmp_idx';

		$this->indexer(new PostgreSQLPlatform(), [$first])->create($run, $this->schema([['postcode', 'huisnummer'], ['huisnummer', 'huisletter']]));

		$this->assertCount(2, $this->compositeStatements());
		$this->assertSame(['huisnummer+huisletter'], $run->compositeIndexes);
	}//end testAFailingCompositeIndexDoesNotStopTheNext()

	/**
	 * A schema without the annotation gets no composite index.
	 *
	 * @return void
	 */
	public function testNoAnnotationMakesNoCompositeIndex(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');

		$this->indexer(new PostgreSQLPlatform())->create($run, $this->schema(null));

		$this->assertSame([], $this->compositeStatements());
		$this->assertSame([], $run->compositeIndexes);
	}//end testNoAnnotationMakesNoCompositeIndex()

	/**
	 * The annotation is a declared key, so a schema keeps it on save.
	 *
	 * @return void
	 */
	public function testTheAnnotationIsADeclaredKey(): void {
		$this->assertContains('x-openregister-indexes', Schema::ANNOTATION_VOCABULARY);
	}//end testTheAnnotationIsADeclaredKey()
}//end class
