<?php

/**
 * A schema entry that is not a property definition must not break index creation.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun;
use OCA\OpenRegister\Db\Schema;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 */
class MagicTableIndexerNonPropertyEntryTest extends TestCase {

	/**
	 * @var array<int, string>
	 */
	private array $statements = [];

	public function testScalarEntryIsSkippedAndTheNextPropertyStillGetsItsIndex(): void {
		$this->index([
			'broken' => 'string',
			'status' => ['type' => 'string', 'facetable' => true],
		]);

		$facet = array_filter($this->statements, static fn (string $sql): bool => str_contains($sql, '_facet_idx'));
		$this->assertCount(1, $facet);
		$this->assertStringContainsString('t_status_facet_idx', (string) reset($facet));
		$this->assertStringNotContainsString('broken', implode("\n", $this->statements));
	}//end testScalarEntryIsSkippedAndTheNextPropertyStillGetsItsIndex()

	/**
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return void
	 */
	private function index(array $properties): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->statements[] = $sql;
				return 0;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);

		$indexer = new MagicTableIndexer($db, $logger, new MagicPgTrgmProbe($db, $logger));
		$indexer->create(new MagicTableIndexRun('t', 'oc_t'), $schema);
	}//end index()
}//end class
