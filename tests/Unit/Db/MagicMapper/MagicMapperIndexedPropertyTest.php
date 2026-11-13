<?php

/**
 * A property asks for an index with `indexed`, independent of `facetable`, and
 * a sync drops the convention-named indexes no property asks for any more.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/modelling-property-index-switch/tasks.md#1-backend
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
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @covers \OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 */
class MagicMapperIndexedPropertyTest extends TestCase {

	/**
	 * Statements the connection ran.
	 *
	 * @var array<int, string>
	 */
	private array $statements = [];

	/**
	 * The logger behind the indexer.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * An indexer on a platform; statements containing a failing fragment throw.
	 *
	 * @param AbstractPlatform   $platform The platform.
	 * @param array<int, string> $failing  Statement fragments that throw.
	 *
	 * @return MagicTableIndexer
	 */
	private function indexer(AbstractPlatform $platform, array $failing = []): MagicTableIndexer {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($failing): int {
				$this->statements[] = $sql;
				foreach ($failing as $fragment) {
					if (str_contains($sql, $fragment) === true) {
						throw new RuntimeException('cannot ' . $fragment);
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

		$this->logger = $this->createMock(LoggerInterface::class);
		return new MagicTableIndexer($db, $this->logger, new MagicPgTrgmProbe($db, $this->logger));
	}//end indexer()

	/**
	 * A schema with these properties.
	 *
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return Schema
	 */
	private function schema(array $properties): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);
		return $schema;
	}//end schema()

	/**
	 * The statements that mention a fragment.
	 *
	 * @param string $fragment The fragment.
	 *
	 * @return array<int, string>
	 */
	private function statementsWith(string $fragment): array {
		return array_values(array_filter($this->statements, static fn (string $sql): bool => str_contains($sql, $fragment)));
	}//end statementsWith()

	/**
	 * `indexed` creates a btree index on PostgreSQL and on MariaDB, and no facet index.
	 *
	 * @return void
	 */
	public function testAnIndexedPropertyGetsABtreeIndexOnBothPlatforms(): void {
		foreach ([new PostgreSQLPlatform(), new MySQLPlatform()] as $platform) {
			$this->statements = [];
			$run = new MagicTableIndexRun('t', 'oc_t');
			$this->indexer($platform)->create($run, $this->schema(['registratiedatum' => ['type' => 'string', 'format' => 'date', 'indexed' => true]]));

			$this->assertSame(
				['CREATE INDEX IF NOT EXISTS t_registratiedatum_sort_idx ON oc_t (registratiedatum)'],
				str_replace(['"', '`'], '', $this->statementsWith('registratiedatum'))
			);
			$this->assertSame(['registratiedatum'], $run->sortIndexes);
			$this->assertSame([], $run->facetIndexes, 'an indexed property is not a facet');
		}
	}//end testAnIndexedPropertyGetsABtreeIndexOnBothPlatforms()

	/**
	 * A property that is indexed and facetable gets one index, the facet's.
	 *
	 * @return void
	 */
	public function testIndexedAndFacetableGiveOneIndex(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');
		$this->indexer(new MySQLPlatform())->create($run, $this->schema(['status' => ['type' => 'integer', 'indexed' => true, 'facetable' => true]]));

		$this->assertCount(1, $this->statementsWith('t_status_'));
		$this->assertCount(1, $this->statementsWith('t_status_facet_idx'));
		$this->assertSame([], $run->sortIndexes);
	}//end testIndexedAndFacetableGiveOneIndex()

	/**
	 * An indexed text property on PostgreSQL also gets the lower() ordering index; a date does not.
	 *
	 * @return void
	 */
	public function testAnIndexedTextPropertyKeepsCaseInsensitiveOrderIndexable(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');
		$this->indexer(new PostgreSQLPlatform())->create(
			$run,
			$this->schema(['titel' => ['type' => 'string', 'indexed' => true], 'datum' => ['type' => 'string', 'format' => 'date', 'indexed' => true]])
		);

		$this->assertCount(1, $this->statementsWith('t_titel_lower_idx'));
		$this->assertSame([], $this->statementsWith('t_datum_lower_idx'));
	}//end testAnIndexedTextPropertyKeepsCaseInsensitiveOrderIndexable()

	/**
	 * A sync drops a convention-named index no flag asks for, and keeps every other.
	 *
	 * @return void
	 */
	public function testSwitchingTheIndexOffDropsItAndLeavesTheRest(): void {
		$schema = $this->schema(
			[
				'registratiedatum' => ['type' => 'string', 'format' => 'date'],
				'status' => ['type' => 'string', 'facetable' => true],
				'name' => ['type' => 'string'],
				'eigenaar' => ['type' => 'object', '$ref' => '#/person', 'objectConfiguration' => ['handling' => 'related-object']],
			]
		);
		$existing = [
			't_registratiedatum_sort_idx',
			't_registratiedatum_handmade',
			't_status_facet_idx',
			't_status_lower_idx',
			't_name_lower_idx',
			't_eigenaar_rel_idx',
			't_vervallen_sort_idx',
			't_uuid_idx',
		];

		$dropped = $this->indexer(new PostgreSQLPlatform())->dropUnasked(new MagicTableIndexRun('t', 'oc_t'), $schema, $existing);

		$this->assertSame(['t_registratiedatum_sort_idx'], $dropped);
		$this->assertSame(['DROP INDEX IF EXISTS t_registratiedatum_sort_idx'], $this->statements);
	}//end testSwitchingTheIndexOffDropsItAndLeavesTheRest()

	/**
	 * Facet and relation indexes go too once nothing asks for them; MariaDB drops on the table.
	 *
	 * @return void
	 */
	public function testAnUnaskedFacetAndRelationIndexAreDroppedOnMysql(): void {
		$schema = $this->schema(['status' => ['type' => 'string'], 'eigenaar' => ['type' => 'object']]);

		$dropped = $this->indexer(new MySQLPlatform())->dropUnasked(
			new MagicTableIndexRun('t', 'oc_t'),
			$schema,
			['t_status_facet_idx', 't_eigenaar_rel_idx', 't_status_lower_idx']
		);

		$this->assertSame(['t_status_facet_idx', 't_eigenaar_rel_idx', 't_status_lower_idx'], $dropped);
		$this->assertSame('DROP INDEX t_status_facet_idx ON oc_t', $this->statements[0]);
	}//end testAnUnaskedFacetAndRelationIndexAreDroppedOnMysql()

	/**
	 * A drop that fails is logged and the next one still runs.
	 *
	 * @return void
	 */
	public function testAFailedDropIsLoggedAndThePassGoesOn(): void {
		$indexer = $this->indexer(new PostgreSQLPlatform(), ['t_a_sort_idx']);
		$this->logger->expects($this->once())->method('warning')->with(
			'[MagicMapper] Could not drop an index no property asks for',
			$this->callback(static fn (array $context): bool => $context['index'] === 't_a_sort_idx')
		);

		$dropped = $indexer->dropUnasked(
			new MagicTableIndexRun('t', 'oc_t'),
			$this->schema(['a' => ['type' => 'integer'], 'b' => ['type' => 'integer']]),
			['t_a_sort_idx', 't_b_sort_idx']
		);

		$this->assertSame(['t_b_sort_idx'], $dropped);
	}//end testAFailedDropIsLoggedAndThePassGoesOn()

	/**
	 * `indexed` is a key of the property vocabulary, so a schema may save it.
	 *
	 * @return void
	 */
	public function testIndexedIsAPropertyModifier(): void {
		$this->assertContains('indexed', PropertyValidatorHandler::vocabularyKeys());
		$this->assertSame('boolean', PropertyValidatorHandler::MODIFIERS['indexed']['value']);
	}//end testIndexedIsAPropertyModifier()
}//end class
