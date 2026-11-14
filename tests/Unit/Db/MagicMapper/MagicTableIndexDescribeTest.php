<?php

/**
 * The index list of a magic table, with the flag that asked for each index.
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
 * @spec openspec/changes/modelling-property-index-switch/specs/zoeken-filteren/spec.md#requirement-a-property-can-ask-for-an-index-without-being-a-facet
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun;
use OCA\OpenRegister\Db\Schema;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun
 */
final class MagicTableIndexDescribeTest extends TestCase {

	/**
	 * The indexer over a connection that runs nothing.
	 *
	 * @return MagicTableIndexer
	 */
	private function indexer(): MagicTableIndexer {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$db->expects($this->never())->method('executeStatement');

		return new MagicTableIndexer($db, new NullLogger(), new MagicPgTrgmProbe($db, new NullLogger()));
	}//end indexer()

	/**
	 * The zaken schema: an indexed date, a facet, a searchable text, a relation and a plain field.
	 *
	 * @return Schema
	 */
	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'registratiedatum' => ['type' => 'string', 'format' => 'date', 'indexed' => true],
				'status' => ['type' => 'string', 'facetable' => true],
				'omschrijving' => ['type' => 'string', 'searchable' => true],
				'zaakType' => ['type' => 'object', '$ref' => '#/zaaktype', 'objectConfiguration' => ['handling' => 'related-object']],
				'kenmerken' => ['type' => 'array', 'items' => ['$ref' => '#/kenmerk']],
				'notitie' => ['type' => 'string'],
				'kapot' => 'not a definition',
			]
		);

		return $schema;
	}//end schema()

	public function testEveryIndexIsListedWithThePropertyFlagThatAskedForIt(): void {
		$existing = [
			'zaken_registratiedatum_sort_idx',
			'zaken_status_facet_idx',
			'zaken_status_lower_idx',
			'zaken_omschrijving_trgm_idx',
			'zaken_omschrijving_lower_idx',
			'zaken_zaak_type_rel_idx',
			'zaken_kenmerken_arr_gin_idx',
			'zaken_name_lower_idx',
			'zaken_handmatig_idx',
			'zaken_verdwenen_facet_idx',
			'zaken_status_facet_idx',
		];

		$indexes = $this->indexer()->describe(run: new MagicTableIndexRun(tableName: 'zaken', fullTableName: 'oc_zaken'), schema: self::schema(), existing: $existing);

		self::assertSame(
			[
				['name' => 'zaken_handmatig_idx', 'property' => null, 'column' => null, 'kind' => 'other', 'flag' => null],
				['name' => 'zaken_kenmerken_arr_gin_idx', 'property' => 'kenmerken', 'column' => 'kenmerken', 'kind' => 'gin', 'flag' => 'relation'],
				['name' => 'zaken_name_lower_idx', 'property' => null, 'column' => null, 'kind' => 'other', 'flag' => null],
				['name' => 'zaken_omschrijving_lower_idx', 'property' => 'omschrijving', 'column' => 'omschrijving', 'kind' => 'btree', 'flag' => 'searchable'],
				['name' => 'zaken_omschrijving_trgm_idx', 'property' => 'omschrijving', 'column' => 'omschrijving', 'kind' => 'trigram', 'flag' => 'searchable'],
				['name' => 'zaken_registratiedatum_sort_idx', 'property' => 'registratiedatum', 'column' => 'registratiedatum', 'kind' => 'btree', 'flag' => 'indexed'],
				['name' => 'zaken_status_facet_idx', 'property' => 'status', 'column' => 'status', 'kind' => 'btree', 'flag' => 'facetable'],
				['name' => 'zaken_status_lower_idx', 'property' => 'status', 'column' => 'status', 'kind' => 'btree', 'flag' => 'facetable'],
				['name' => 'zaken_verdwenen_facet_idx', 'property' => null, 'column' => null, 'kind' => 'other', 'flag' => null],
				['name' => 'zaken_zaak_type_rel_idx', 'property' => 'zaakType', 'column' => 'zaak_type', 'kind' => 'btree', 'flag' => 'relation'],
			],
			$indexes
		);
	}//end testEveryIndexIsListedWithThePropertyFlagThatAskedForIt()

	public function testALowerIndexNamesTheFirstFlagStillSetOrNone(): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'a' => ['type' => 'string', 'indexed' => true, 'searchable' => true],
				'b' => ['type' => 'string'],
			]
		);

		$indexes = $this->indexer()->describe(run: new MagicTableIndexRun(tableName: 't', fullTableName: 'oc_t'), schema: $schema, existing: ['t_a_lower_idx', 't_b_lower_idx']);

		self::assertSame('indexed', $indexes[0]['flag']);
		self::assertNull($indexes[1]['flag'], 'An index no flag asks for any more names none; the next sync drops it.');
		self::assertSame('b', $indexes[1]['property']);
		self::assertSame([], $this->indexer()->describe(run: new MagicTableIndexRun(tableName: 't', fullTableName: 'oc_t'), schema: $schema, existing: []));
	}//end testALowerIndexNamesTheFirstFlagStillSetOrNone()
}//end class
