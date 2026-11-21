<?php

/**
 * The statements MagicTableIndexer runs to create and drop the indexes of a magic table.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIdentityIndex
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators
 * @uses   \OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator
 */
final class MagicTableIndexerCreateTest extends TestCase {

	/**
	 * Every statement the connection was asked to run, in order.
	 *
	 * @var array<int, string>
	 */
	private array $statements = [];

	/**
	 * Every log call: [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * An indexer whose statements throw when they contain one of the given fragments.
	 *
	 * @param AbstractPlatform   $platform The platform.
	 * @param bool               $trgm     Whether pg_trgm is installed.
	 * @param array<int, string> $failing  Statement fragments that make executeStatement throw.
	 *
	 * @return MagicTableIndexer
	 */
	private function indexer(AbstractPlatform $platform, bool $trgm = false, array $failing = []): MagicTableIndexer {
		/** @var IDBConnection&MockObject $db */
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($failing): int {
				$this->statements[] = $sql;
				foreach ($failing as $fragment) {
					if (str_contains($sql, $fragment) === true) {
						throw new RuntimeException('cannot run ' . $fragment);
					}
				}

				return 0;
			}
		);
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($trgm === true ? 1 : 0);
		$prepared = $this->createMock(IPreparedStatement::class);
		$prepared->method('execute')->willReturn($result);
		$db->method('prepare')->willReturn($prepared);

		/** @var LoggerInterface&MockObject $logger */
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, (string)$message, $context];
				}
			);
		}

		return new MagicTableIndexer($db, $logger, new MagicPgTrgmProbe($db, $logger));
	}//end indexer()

	/**
	 * A schema with the given properties and configuration.
	 *
	 * @param array<string, mixed> $properties    The property definitions.
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return Schema
	 */
	private static function schema(array $properties, array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * The statements after the base indexes, which every table gets.
	 *
	 * @return array<int, string>
	 */
	private function propertyStatements(): array {
		return array_values(array_slice($this->statements, 10));
	}//end propertyStatements()

	/**
	 * The log calls of one level.
	 *
	 * @param string $level The level.
	 *
	 * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private function logsAt(string $level): array {
		return array_values(array_filter($this->logs, static fn (array $log): bool => $log[0] === $level));
	}//end logsAt()

	public function testPostgresCreatesTheBasePlatformAndPropertyIndexesAndReportsThem(): void {
		$run = new MagicTableIndexRun(tableName: 'zaken', fullTableName: 'oc_zaken');
		$schema = self::schema(
			[
				'zaakType' => ['type' => 'object', '$ref' => '#/zaaktype', 'objectConfiguration' => ['handling' => 'related-object']],
				'kenmerken' => ['type' => 'array', 'items' => ['$ref' => '#/kenmerk']],
				'betrokkenen' => ['type' => 'array', 'inversedBy' => 'zaak'],
				'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
				'status' => ['type' => 'string', 'facetable' => true, 'indexed' => true],
				'registratiedatum' => ['type' => 'string', 'format' => 'date', 'indexed' => true],
				'omschrijving' => ['type' => 'string', 'searchable' => true],
				'kapot' => 'not a definition',
			],
			['uniqueConstraints' => [['name' => 'sleutel', 'properties' => ['status'], 'action' => 'refuse', 'identity' => true]]]
		);

		$this->indexer(new PostgreSQLPlatform(), trgm: true)->create($run, $schema);

		self::assertSame('CREATE UNIQUE INDEX IF NOT EXISTS zaken_uuid_idx ON oc_zaken (_uuid)', $this->statements[0]);
		self::assertSame('CREATE INDEX IF NOT EXISTS zaken_last_user_activity_idx ON oc_zaken (_last_user_activity)', $this->statements[9]);
		$identity = 'zaken_identity_' . substr(md5('status'), 0, 8) . '_idx';
		self::assertSame(
			[
				'CREATE INDEX IF NOT EXISTS zaken_relations_gin_idx ON oc_zaken USING GIN (_relations)',
				'CREATE INDEX IF NOT EXISTS zaken_live_owner_idx ON oc_zaken (_owner) WHERE _deleted IS NULL',
				'CREATE INDEX IF NOT EXISTS zaken_name_lower_idx ON oc_zaken (LOWER(CAST(_name AS TEXT)))',
				'CREATE INDEX IF NOT EXISTS zaken_name_trgm_idx ON oc_zaken USING GIN (_name gin_trgm_ops)',
				'CREATE INDEX IF NOT EXISTS zaken_name_foldgin_idx ON oc_zaken USING GIN ((openregister_fold(_name::text)) gin_trgm_ops)',
				'CREATE INDEX IF NOT EXISTS zaken_zaak_type_rel_idx ON oc_zaken ("zaak_type")',
				'CREATE INDEX IF NOT EXISTS zaken_kenmerken_arr_gin_idx ON oc_zaken USING GIN ("kenmerken")',
				'CREATE INDEX IF NOT EXISTS zaken_betrokkenen_arr_gin_idx ON oc_zaken USING GIN ("betrokkenen")',
				'CREATE INDEX IF NOT EXISTS zaken_status_facet_idx ON oc_zaken ("status")',
				'CREATE INDEX IF NOT EXISTS zaken_status_lower_idx ON oc_zaken (LOWER(CAST("status" AS TEXT)))',
				'CREATE INDEX IF NOT EXISTS zaken_registratiedatum_sort_idx ON oc_zaken ("registratiedatum")',
				'CREATE INDEX IF NOT EXISTS zaken_omschrijving_trgm_idx ON oc_zaken USING GIN ("omschrijving" gin_trgm_ops)',
				'CREATE INDEX IF NOT EXISTS zaken_omschrijving_foldgin_idx ON oc_zaken USING GIN ((openregister_fold("omschrijving"::text)) gin_trgm_ops)',
				'CREATE INDEX IF NOT EXISTS zaken_omschrijving_lower_idx ON oc_zaken (LOWER(CAST("omschrijving" AS TEXT)))',
				"CREATE INDEX IF NOT EXISTS {$identity} ON oc_zaken (\"status\")",
			],
			$this->propertyStatements()
		);

		self::assertTrue($run->isPostgres);
		self::assertSame(['zaak_type', 'kenmerken (GIN)', 'betrokkenen (GIN)'], $run->relationIndexes);
		self::assertSame(['status'], $run->facetIndexes);
		self::assertSame(['registratiedatum'], $run->sortIndexes, 'A facet already serves the sort, so status gets no second index.');
		self::assertSame(['omschrijving'], $run->searchableIndexes);
		self::assertSame($identity, $run->identityIndex);
		self::assertSame(
			[
				[
					'debug',
					'[MagicMapper] Created table indexes',
					[
						'tableName' => 'zaken',
						'baseIndexCount' => 11,
						'relationIndexes' => ['zaak_type', 'kenmerken (GIN)', 'betrokkenen (GIN)'],
						'facetIndexes' => ['status'],
						'searchableIndexes' => ['omschrijving'],
						'identityIndex' => $identity,
					],
				],
			],
			$this->logs
		);
	}//end testPostgresCreatesTheBasePlatformAndPropertyIndexesAndReportsThem()

	public function testFailedPropertyIndexesAreSkippedLoggedAndNotReported(): void {
		$run = new MagicTableIndexRun(tableName: 't', fullTableName: 'oc_t');
		$schema = self::schema(
			[
				'status' => ['type' => 'string', 'facetable' => true],
				'omschrijving' => ['type' => 'string', 'searchable' => true],
				'ouder' => ['type' => 'object', '$ref' => '#/ouder', 'objectConfiguration' => ['handling' => 'related-object']],
				'labels' => ['type' => 'array', 'items' => ['$ref' => '#/label']],
				'volgorde' => ['type' => 'integer', 'indexed' => true],
			]
		);

		$this->indexer(
			new PostgreSQLPlatform(),
			trgm: true,
			failing: ['_name gin_trgm_ops', 'status_facet_idx', 'omschrijving_trgm_idx', 'status_lower_idx', 'ouder_rel_idx', 'labels_arr_gin_idx', 'volgorde_sort_idx']
		)->create($run, $schema);

		self::assertSame([], $run->relationIndexes);
		self::assertSame([], $run->facetIndexes);
		self::assertSame([], $run->sortIndexes);
		self::assertSame([], $run->searchableIndexes);
		self::assertNull($run->identityIndex);
		self::assertContains('CREATE INDEX IF NOT EXISTS t_omschrijving_lower_idx ON oc_t (LOWER(CAST("omschrijving" AS TEXT)))', $this->statements);
		self::assertNotContains('CREATE INDEX IF NOT EXISTS t_volgorde_lower_idx ON oc_t (LOWER(CAST("volgorde" AS TEXT)))', $this->statements, 'An integer orders without lower().');

		self::assertSame(
			[
				['debug', '[MagicMapper] Skipped baseline _name trgm index', ['tableName' => 't', 'error' => 'cannot run _name gin_trgm_ops']],
				['debug', '[MagicMapper] Skipped lower() ordering index', ['tableName' => 't', 'column' => 'status', 'error' => 'cannot run status_lower_idx']],
				['debug', '[MagicMapper] Created table indexes', ['tableName' => 't', 'baseIndexCount' => 11, 'relationIndexes' => [], 'facetIndexes' => [], 'searchableIndexes' => []]],
			],
			$this->logsAt('debug')
		);
		self::assertSame(
			[
				[
					'warning',
					'[MagicMapper] Skipped searchable trgm index (incompatible column or already present)',
					['tableName' => 't', 'column' => 'omschrijving', 'error' => 'cannot run omschrijving_trgm_idx'],
				],
			],
			$this->logsAt('warning')
		);
	}//end testFailedPropertyIndexesAreSkippedLoggedAndNotReported()

	public function testMySqlGetsTheDeletedOwnerIndexAndNoPostgresOnlyIndexes(): void {
		$run = new MagicTableIndexRun(tableName: 't', fullTableName: 'oc_t');
		$schema = self::schema(
			[
				'labels' => ['type' => 'array', 'items' => ['$ref' => '#/label']],
				'status' => ['type' => 'string', 'facetable' => true, 'searchable' => true],
			]
		);

		$this->indexer(new MySQLPlatform(), failing: ['_deleted_owner_idx'])->create($run, $schema);

		self::assertFalse($run->isPostgres);
		self::assertSame(
			[
				'CREATE INDEX t_deleted_owner_idx ON oc_t (_deleted, _owner)',
				'CREATE INDEX IF NOT EXISTS t_status_facet_idx ON oc_t (`status`)',
			],
			$this->propertyStatements()
		);
		self::assertSame(['status'], $run->facetIndexes);
		self::assertSame([], $run->relationIndexes, 'An array column gets no GIN index outside PostgreSQL.');
		self::assertSame(
			['debug', '[MagicMapper] Skipped _deleted/_owner index', ['tableName' => 't', 'error' => 'cannot run _deleted_owner_idx']],
			$this->logs[0]
		);
	}//end testMySqlGetsTheDeletedOwnerIndexAndNoPostgresOnlyIndexes()

	public function testAFailingBaseIndexStopsThePassWithAWarning(): void {
		$run = new MagicTableIndexRun(tableName: 't', fullTableName: 'oc_t');

		$this->indexer(new PostgreSQLPlatform(), failing: ['_register_schema_idx'])->create($run, self::schema(['status' => ['type' => 'string', 'facetable' => true]]));

		self::assertCount(2, $this->statements, 'Nothing runs after the failing statement.');
		self::assertSame(
			[['warning', '[MagicMapper] Failed to create some table indexes', ['tableName' => 't', 'error' => 'cannot run _register_schema_idx']]],
			$this->logs
		);
		self::assertSame([], $run->facetIndexes);
	}//end testAFailingBaseIndexStopsThePassWithAWarning()

	public function testPostgresDropsOnlyIndexesNoPropertyAsksForAndReportsTheOnesDropped(): void {
		$run = new MagicTableIndexRun(tableName: 't', fullTableName: 'oc_t');
		$schema = self::schema(
			[
				'status' => ['type' => 'string', 'facetable' => true],
				'datum' => ['type' => 'string', 'format' => 'date', 'indexed' => true],
				'ouder' => ['type' => 'object', '$ref' => '#/ouder', 'objectConfiguration' => ['handling' => 'related-object']],
				'notitie' => ['type' => 'string'],
				'kapot' => 'not a definition',
			]
		);
		$existing = [
			't_status_facet_idx',
			't_status_lower_idx',
			't_status_sort_idx',
			't_datum_sort_idx',
			't_datum_lower_idx',
			't_ouder_rel_idx',
			't_notitie_facet_idx',
			't_notitie_lower_idx',
			't_name_lower_idx',
			't_uuid_idx',
			't_identity_0123abcd_idx',
		];

		$dropped = $this->indexer(new PostgreSQLPlatform(), failing: ['t_notitie_lower_idx'])->dropUnasked(run: $run, schema: $schema, existing: $existing);

		self::assertSame(['t_status_sort_idx', 't_datum_lower_idx', 't_notitie_facet_idx', 't_identity_0123abcd_idx'], $dropped);
		self::assertSame(
			[
				'DROP INDEX IF EXISTS t_status_sort_idx',
				'DROP INDEX IF EXISTS t_datum_lower_idx',
				'DROP INDEX IF EXISTS t_notitie_facet_idx',
				'DROP INDEX IF EXISTS t_notitie_lower_idx',
				'DROP INDEX IF EXISTS t_identity_0123abcd_idx',
			],
			$this->statements
		);
		self::assertSame(
			[
				[
					'warning',
					'[MagicMapper] Could not drop an index no property asks for',
					['tableName' => 't', 'index' => 't_notitie_lower_idx', 'error' => 'cannot run t_notitie_lower_idx'],
				],
			],
			$this->logs
		);
	}//end testPostgresDropsOnlyIndexesNoPropertyAsksForAndReportsTheOnesDropped()

	public function testMySqlDropsAnIndexOnItsTable(): void {
		$run = new MagicTableIndexRun(tableName: 't', fullTableName: 'oc_t');
		$schema = self::schema(['status' => ['type' => 'string']]);

		$dropped = $this->indexer(new MySQLPlatform())->dropUnasked(run: $run, schema: $schema, existing: ['t_status_facet_idx', 't_status_lower_idx']);

		self::assertSame(['t_status_facet_idx', 't_status_lower_idx'], $dropped);
		self::assertSame(['DROP INDEX t_status_facet_idx ON oc_t', 'DROP INDEX t_status_lower_idx ON oc_t'], $this->statements);
		self::assertFalse($run->isPostgres);
	}//end testMySqlDropsAnIndexOnItsTable()
}//end class
