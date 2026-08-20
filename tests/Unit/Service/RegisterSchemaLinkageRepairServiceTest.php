<?php

/**
 * Unit tests for RegisterSchemaLinkageRepairService.
 *
 * openspec/changes/register-scoped-slug-resolution. Proves that a register whose
 * `schemas` list was lost can be rebuilt from the physical per-pair object tables,
 * and — just as important — that the rebuild never guesses and never subtracts.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\RegisterSchemaLinkageRepairService;
use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the linkage repair service.
 *
 * Prepared statements are PHPUnit mocks rather than a hand-written fake: an
 * `implements IPreparedStatement` double has to track that interface's exact
 * signatures (`execute()` returns `IResult`, not `bool`), and a drifted double is
 * a test failure that says nothing about the code under test.
 */
class RegisterSchemaLinkageRepairServiceTest extends TestCase {

	/**
	 * Build a prepared-statement mock yielding the given rows then false.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows to yield.
	 *
	 * @return IPreparedStatement The mock.
	 */
	private function statement(array $rows): IPreparedStatement {
		$stmt = $this->createMock(IPreparedStatement::class);

		$queue = $rows;
		$stmt->method('fetch')->willReturnCallback(
			static function () use (&$queue): mixed {
				$row = array_shift($queue);
				return ($row ?? false);
			}
		);

		return $stmt;
	}//end statement()

	/**
	 * Build a service whose catalogue reports the given shard tables.
	 *
	 * @param array<int, string>        $tables    Shard table names.
	 * @param array<string, int>        $rowCounts Table name => row count.
	 * @param array<int, Register>      $registers Registers the mapper returns.
	 * @param RegisterMapper|null       $mapperOut Receives the mapper, for assertions.
	 *
	 * @return RegisterSchemaLinkageRepairService The service.
	 */
	private function makeService(
		array $tables,
		array $rowCounts,
		array $registers,
		&$mapperOut=null,
	): RegisterSchemaLinkageRepairService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($tables, $rowCounts): IPreparedStatement {
				if (str_contains($sql, 'information_schema.tables') === true) {
					return $this->statement(
						array_map(static fn (string $t): array => ['table_name' => $t], $tables)
					);
				}

				foreach ($rowCounts as $table => $count) {
					if (str_contains($sql, $table) === true) {
						return $this->statement([['c' => $count]]);
					}
				}

				return $this->statement([['c' => 0]]);
			}
		);

		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('findAll')->willReturn($registers);
		$mapperOut = $mapper;

		return new RegisterSchemaLinkageRepairService(
			db: $db,
			registerMapper: $mapper,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end makeService()

	/**
	 * Build a register.
	 *
	 * @param int        $id      The id.
	 * @param string     $slug    The slug.
	 * @param array|null $schemas The schemas list.
	 *
	 * @return Register The register.
	 */
	private function register(int $id, string $slug, ?array $schemas): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);
		$register->setSchemas($schemas);
		return $register;
	}//end register()

	/**
	 * REQ: linkage is reconstructed for a register whose list was lost.
	 *
	 * The live shape from 2026-08-16: register 6 with an empty list and three
	 * populated shard tables.
	 *
	 * @return void
	 */
	public function testReconstructsLostLinkageFromTableNames(): void {
		$service = $this->makeService(
			tables: [
				'oc_openregister_table_6_9173',
				'oc_openregister_table_6_9174',
				'oc_openregister_table_6_9177',
			],
			rowCounts: [
				'oc_openregister_table_6_9173' => 1,
				'oc_openregister_table_6_9174' => 2,
				'oc_openregister_table_6_9177' => 4,
			],
			registers: [$this->register(id: 6, slug: 'document', schemas: [])]
		);

		$report = $service->inspect();

		$this->assertCount(1, $report);
		$this->assertSame(6, $report[0]['registerId']);
		$this->assertSame('document', $report[0]['registerSlug']);
		$this->assertSame([9173, 9174, 9177], array_keys($report[0]['recoverable']));

		// Row counts are reported so weak evidence (empty table) is visibly
		// different from strong (a table holding rows).
		$this->assertSame(4, $report[0]['recoverable'][9177]);
	}//end testReconstructsLostLinkageFromTableNames()

	/**
	 * REQ: no physical evidence means no recovery, and NO guessing.
	 *
	 * The rejected heuristics are the point: slug similarity and application
	 * ownership both failed on the live data (nine same-slug schemas, all owned by
	 * `docudesk`), so absence of a table must yield nothing at all.
	 *
	 * @return void
	 */
	public function testReportsNothingWithoutPhysicalEvidence(): void {
		$service = $this->makeService(
			tables: ['oc_openregister_table_99_1'],
			rowCounts: [],
			registers: [$this->register(id: 6, slug: 'document', schemas: [])]
		);

		$this->assertSame([], $service->inspect());
	}//end testReportsNothingWithoutPhysicalEvidence()

	/**
	 * REQ: a register already carrying the id is not reported again.
	 *
	 * @return void
	 */
	public function testAlreadyLinkedIdsAreNotProposed(): void {
		$service = $this->makeService(
			tables: ['oc_openregister_table_6_9177'],
			rowCounts: ['oc_openregister_table_6_9177' => 4],
			registers: [$this->register(id: 6, slug: 'document', schemas: [9177])]
		);

		$this->assertSame([], $service->inspect());
	}//end testAlreadyLinkedIdsAreNotProposed()

	/**
	 * REQ: the repair is additive — an id with no physical table is retained.
	 *
	 * A schema may legitimately be linked before its first object is written, so a
	 * missing table is not evidence of "not linked". A subtractive repair would
	 * delete correct configuration from an unused register.
	 *
	 * @return void
	 */
	public function testRepairIsAdditiveAndNeverRemoves(): void {
		$existing = $this->register(id: 6, slug: 'document', schemas: [500]);

		$service = $this->makeService(
			tables: ['oc_openregister_table_6_9177'],
			rowCounts: ['oc_openregister_table_6_9177' => 4],
			registers: [$existing],
			mapperOut: $mapper
		);

		$mapper->method('find')->willReturn($existing);
		$mapper->expects($this->once())->method('update')->with($existing);

		$merged = $service->apply(registerId: 6, schemaIds: [9177]);

		$this->assertSame([500, 9177], $merged, 'the pre-existing id 500 must survive the repair');
		$this->assertSame([500, 9177], $existing->getSchemas());
	}//end testRepairIsAdditiveAndNeverRemoves()

	/**
	 * REQ: applying the same repair twice does not duplicate ids.
	 *
	 * @return void
	 */
	public function testRepairIsIdempotent(): void {
		$existing = $this->register(id: 6, slug: 'document', schemas: [9177]);

		$service = $this->makeService(
			tables: ['oc_openregister_table_6_9177'],
			rowCounts: ['oc_openregister_table_6_9177' => 4],
			registers: [$existing],
			mapperOut: $mapper
		);

		$mapper->method('find')->willReturn($existing);

		$this->assertSame([9177], $service->apply(registerId: 6, schemaIds: [9177]));
	}//end testRepairIsIdempotent()

	/**
	 * REQ: a malformed shard-table name is ignored rather than mis-parsed.
	 *
	 * @return void
	 */
	public function testMalformedTableNamesAreIgnored(): void {
		$service = $this->makeService(
			tables: [
				'oc_openregister_table_6_notanumber',
				'oc_openregister_table_6',
				'oc_openregister_table_6_9177_extra',
				'oc_openregister_table_6_9177',
			],
			rowCounts: ['oc_openregister_table_6_9177' => 4],
			registers: [$this->register(id: 6, slug: 'document', schemas: [])]
		);

		$report = $service->inspect();

		$this->assertCount(1, $report);
		$this->assertSame([9177], array_keys($report[0]['recoverable']));
	}//end testMalformedTableNamesAreIgnored()

	/**
	 * REQ: a single register can be targeted, leaving the others alone.
	 *
	 * @return void
	 */
	public function testInspectCanTargetOneRegister(): void {
		$service = $this->makeService(
			tables: ['oc_openregister_table_6_9177', 'oc_openregister_table_7_5084'],
			rowCounts: ['oc_openregister_table_6_9177' => 4, 'oc_openregister_table_7_5084' => 0],
			registers: [
				$this->register(id: 6, slug: 'document', schemas: []),
				$this->register(id: 7, slug: 'openbuild', schemas: []),
			]
		);

		$this->assertCount(2, $service->inspect());

		$scoped = $service->inspect(registerId: 6);
		$this->assertCount(1, $scoped);
		$this->assertSame(6, $scoped[0]['registerId']);
	}//end testInspectCanTargetOneRegister()
}//end class
