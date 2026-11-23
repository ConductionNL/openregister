<?php

/**
 * MagicTableIndexer makes trigram indexes on the folded text a folded search compares.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-object-search-ignores-accents-where-the-database-supports-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun;
use OCA\OpenRegister\Db\MagicMapper\MagicUnaccentProbe;
use OCA\OpenRegister\Db\MagicMapper\SearchFolding;
use OCA\OpenRegister\Db\Schema;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * On PostgreSQL with pg_trgm and `openregister_fold()`, `_name` and every
 * searchable property get a GIN index on `openregister_fold(col::text)`, the
 * expression {@see SearchFolding::column()} compares; anywhere else none.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIdentityIndex
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnaccentProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SearchFolding
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 */
class MagicTableIndexerFoldedIndexTest extends TestCase {

	/**
	 * Statements the connection ran (failed ones included).
	 *
	 * @var array<int, string>
	 */
	private array $statements = [];

	/**
	 * Index the given properties on a platform where the probes answer as given.
	 *
	 * @param AbstractPlatform     $platform   The platform.
	 * @param bool                 $trgm       Whether pg_trgm is installed.
	 * @param bool                 $fold       Whether `openregister_fold()` exists.
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param string|null          $failing    A statement fragment that makes executeStatement throw.
	 *
	 * @return array<int, string> The folded index statements.
	 */
	private function folded(AbstractPlatform $platform, bool $trgm, bool $fold, array $properties, ?string $failing = null): array {
		$this->statements = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($failing): int {
				$this->statements[] = $sql;
				if ($failing !== null && str_contains($sql, $failing) === true) {
					throw new RuntimeException('cannot create');
				}

				return 0;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$trgmProbe = new MagicPgTrgmProbe($db, $logger);
		(new ReflectionProperty($trgmProbe, 'available'))->setValue($trgmProbe, $trgm);
		$unaccent = new MagicUnaccentProbe($db, $logger);
		(new ReflectionProperty($unaccent, 'reason'))->setValue($unaccent, $fold === true ? '' : MagicUnaccentProbe::REASON_NO_EXTENSION);

		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);

		(new MagicTableIndexer($db, $logger, $trgmProbe, $unaccent))->create(new MagicTableIndexRun('t', 'oc_t'), $schema);

		return array_values(array_filter($this->statements, static fn (string $sql): bool => str_contains($sql, MagicTableIndexer::FOLDED_TRIGRAM_SUFFIX)));
	}//end folded()

	/**
	 * `_name` and a searchable property get the folded index; a property that is not searchable does not.
	 *
	 * @return void
	 */
	public function testPostgresIndexesTheFoldedTextOfNameAndSearchableProperties(): void {
		$statements = $this->folded(
			platform: new PostgreSQLPlatform(),
			trgm: true,
			fold: true,
			properties: ['title' => ['type' => 'string', 'searchable' => true], 'code' => ['type' => 'string']]
		);

		$this->assertSame(
			[
				'CREATE INDEX IF NOT EXISTS t_name_foldgin_idx ON oc_t USING GIN ((openregister_fold(_name::text)) gin_trgm_ops)',
				'CREATE INDEX IF NOT EXISTS t_title_foldgin_idx ON oc_t USING GIN ((openregister_fold("title"::text)) gin_trgm_ops)',
			],
			$statements
		);
		$folding = new SearchFolding(platform: SearchFolding::PLATFORM_POSTGRES, folded: true);
		$this->assertStringContainsString('(' . $folding->column(quotedColumn: '"title"', nullSafe: false) . ')', $statements[1], 'the index is on the expression a folded search compares');
	}//end testPostgresIndexesTheFoldedTextOfNameAndSearchableProperties()

	/**
	 * Without the wrapper, without pg_trgm or off PostgreSQL there is no folded index.
	 *
	 * @return void
	 */
	public function testNoFoldedIndexWhereItCannotBeUsed(): void {
		$searchable = ['title' => ['type' => 'string', 'searchable' => true]];

		$this->assertSame([], $this->folded(platform: new PostgreSQLPlatform(), trgm: true, fold: false, properties: $searchable));
		$this->assertSame([], $this->folded(platform: new PostgreSQLPlatform(), trgm: false, fold: true, properties: $searchable));
		$this->assertSame([], $this->folded(platform: new MySQLPlatform(), trgm: true, fold: true, properties: $searchable));
	}//end testNoFoldedIndexWhereItCannotBeUsed()

	/**
	 * A folded index that cannot be made is skipped and the pass goes on.
	 *
	 * @return void
	 */
	public function testAFailedFoldedIndexDoesNotStopThePass(): void {
		$statements = $this->folded(
			platform: new PostgreSQLPlatform(),
			trgm: true,
			fold: true,
			properties: ['title' => ['type' => 'string', 'searchable' => true]],
			failing: 't_name_foldgin_idx'
		);

		$this->assertCount(2, $statements);
		$this->assertContains('CREATE INDEX IF NOT EXISTS t_title_lower_idx ON oc_t (LOWER(CAST("title" AS TEXT)))', $this->statements, 'the pass goes on to the next index');
	}//end testAFailedFoldedIndexDoesNotStopThePass()
}//end class
