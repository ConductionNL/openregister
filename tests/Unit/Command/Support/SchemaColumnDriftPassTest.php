<?php

/**
 * Unit test of the per-schema column-drift pass of `openregister:reconcile-magic-tables`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command\Support;

use OCA\OpenRegister\Command\Support\SchemaColumnDriftPass;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Each branch of reconcile() and summarise(), on a mocked magic mapper.
 */
class SchemaColumnDriftPassTest extends TestCase {

	private const CURRENT = ['id' => ['type' => 'integer'], 'amount' => ['type' => 'decimal', 'precision' => 5]];

	private const REQUIRED = ['id' => ['type' => 'integer'], 'amount' => ['type' => 'decimal', 'precision' => 20], 'title' => ['type' => 'string'], '_archived' => ['type' => 'boolean']];

	private BufferedOutput $output;

	/** @var MagicMapperInterface&MockObject */
	private MagicMapperInterface $mapper;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		$this->output = new BufferedOutput();
		$this->mapper = $this->createMock(MagicMapperInterface::class);
		$this->register = new Register();
		$this->register->setId(7);
		$this->schema = new Schema();
		$this->schema->setId(42);
		$this->schema->setSlug('case');
	}//end setUp()

	/**
	 * A pass with the given --apply flag; the metadata pass handled `_archived`.
	 */
	private function pass(bool $apply): SchemaColumnDriftPass {
		return new SchemaColumnDriftPass(output: $this->output, magicMapper: $this->mapper, options: ['apply' => $apply, 'metadata' => ['_archived']]);
	}//end pass()

	/**
	 * Stub the inspection to report these missing and narrow columns.
	 */
	private function drift(array $missing, array $narrow): void {
		$this->mapper->method('getTableNameForRegisterSchema')->with($this->register, $this->schema)->willReturn('openregister_table_7_42');
		$this->mapper->method('getExistingTableColumns')->with('openregister_table_7_42')->willReturn(self::CURRENT);
		$this->mapper->method('buildTableColumnsFromSchema')->with($this->schema)->willReturn(self::REQUIRED);
		$this->mapper->method('findMissingColumns')->with(self::CURRENT, self::REQUIRED)->willReturn($missing);
		$this->mapper->method('findDecimalColumnsNeedingWiden')->with(self::CURRENT, self::REQUIRED)->willReturn($narrow);
	}//end drift()

	public function testInspectionThatThrowsIsSkippedWithZeroCounts(): void {
		$this->mapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_7_42');
		$this->mapper->method('getExistingTableColumns')->willThrowException(new RuntimeException('no such table'));
		$this->mapper->expects($this->never())->method('ensureTableForRegisterSchema');

		$counts = $this->pass(apply: true)->reconcile(register: $this->register, schema: $this->schema);

		$this->assertSame(['tables' => 0, 'columns' => 0, 'repaired' => 0, 'failed' => 0], $counts);
		$this->assertSame("skip r7/case (42): no such table\n", $this->output->fetch());
	}//end testInspectionThatThrowsIsSkippedWithZeroCounts()

	public function testNoDriftPrintsNothingAndRepairsNothing(): void {
		$this->drift(missing: [], narrow: []);
		$this->mapper->expects($this->never())->method('ensureTableForRegisterSchema');
		$this->mapper->expects($this->never())->method('widenDecimalColumns');

		$counts = $this->pass(apply: true)->reconcile(register: $this->register, schema: $this->schema);

		$this->assertSame(['tables' => 0, 'columns' => 0, 'repaired' => 0, 'failed' => 0], $counts);
		$this->assertSame('', $this->output->fetch());
	}//end testNoDriftPrintsNothingAndRepairsNothing()

	public function testOnlyMetadataColumnsMissingCountsAsNoDrift(): void {
		$this->drift(missing: ['_archived' => self::REQUIRED['_archived']], narrow: []);
		$this->mapper->expects($this->never())->method('ensureTableForRegisterSchema');

		$counts = $this->pass(apply: true)->reconcile(register: $this->register, schema: $this->schema);

		$this->assertSame(['tables' => 0, 'columns' => 0, 'repaired' => 0, 'failed' => 0], $counts);
		$this->assertSame('', $this->output->fetch());
	}//end testOnlyMetadataColumnsMissingCountsAsNoDrift()

	public function testDryRunReportsMissingExceptMetadataAndNarrowWithoutRepairing(): void {
		$this->drift(missing: ['title' => self::REQUIRED['title'], '_archived' => self::REQUIRED['_archived']], narrow: ['amount']);
		$this->mapper->expects($this->never())->method('ensureTableForRegisterSchema');
		$this->mapper->expects($this->never())->method('widenDecimalColumns');

		$counts = $this->pass(apply: false)->reconcile(register: $this->register, schema: $this->schema);

		$this->assertSame(['tables' => 1, 'columns' => 2, 'repaired' => 0, 'failed' => 0], $counts);
		$this->assertSame(
			"r7 schema=case(#42) missing 1: title\nr7 schema=case(#42) number columns to widen 1: amount\n",
			$this->output->fetch()
		);
	}//end testDryRunReportsMissingExceptMetadataAndNarrowWithoutRepairing()

	public function testNarrowOnlyPrintsOnlyTheWidenLine(): void {
		$this->drift(missing: [], narrow: ['amount']);

		$counts = $this->pass(apply: false)->reconcile(register: $this->register, schema: $this->schema);

		$this->assertSame(['tables' => 1, 'columns' => 1, 'repaired' => 0, 'failed' => 0], $counts);
		$this->assertSame("r7 schema=case(#42) number columns to widen 1: amount\n", $this->output->fetch());
	}//end testNarrowOnlyPrintsOnlyTheWidenLine()

	public function testApplyEnsuresTheTableAndWidensItsColumns(): void {
		$this->drift(missing: ['title' => self::REQUIRED['title']], narrow: []);
		$this->mapper->expects($this->once())->method('ensureTableForRegisterSchema')->with($this->register, $this->schema)->willReturn(true);
		$this->mapper->expects($this->once())->method('widenDecimalColumns')->with('openregister_table_7_42', new MagicColumnDrift(self::CURRENT, self::REQUIRED))->willReturn([]);

		$counts = $this->pass(apply: true)->reconcile(register: $this->register, schema: $this->schema);

		$this->assertSame(['tables' => 1, 'columns' => 1, 'repaired' => 1, 'failed' => 0], $counts);
		$this->assertSame("r7 schema=case(#42) missing 1: title\n", $this->output->fetch());
	}//end testApplyEnsuresTheTableAndWidensItsColumns()

	public function testApplyThatThrowsCountsAFailureAndSaysWhy(): void {
		$this->drift(missing: ['title' => self::REQUIRED['title']], narrow: ['amount']);
		$this->mapper->expects($this->once())->method('ensureTableForRegisterSchema')->willThrowException(new RuntimeException('disk full'));
		$this->mapper->expects($this->never())->method('widenDecimalColumns');

		$counts = $this->pass(apply: true)->reconcile(register: $this->register, schema: $this->schema);

		$this->assertSame(['tables' => 1, 'columns' => 2, 'repaired' => 0, 'failed' => 1], $counts);
		$this->assertStringEndsWith("  repair failed: disk full\n", $this->output->fetch());
	}//end testApplyThatThrowsCountsAFailureAndSaysWhy()

	public function testSummariseWithNoTablesSaysNoDrift(): void {
		$this->assertSame(0, $this->pass(apply: true)->summarise(counts: ['tables' => 0, 'columns' => 0, 'repaired' => 0, 'failed' => 0]));
		$this->assertSame("No magic-table column drift found.\n", $this->output->fetch());
	}//end testSummariseWithNoTablesSaysNoDrift()

	public function testSummariseOnADryRunAsksForApply(): void {
		$this->assertSame(0, $this->pass(apply: false)->summarise(counts: ['tables' => 2, 'columns' => 5, 'repaired' => 0, 'failed' => 0]));
		$this->assertSame("2 table(s), 5 column(s) would be repaired. Re-run with --apply.\n", $this->output->fetch());
	}//end testSummariseOnADryRunAsksForApply()

	public function testSummariseAfterACleanApplyExitsZero(): void {
		$this->assertSame(0, $this->pass(apply: true)->summarise(counts: ['tables' => 2, 'columns' => 5, 'repaired' => 2, 'failed' => 0]));
		$this->assertSame("Repaired 2/2 table(s); 0 failed.\n", $this->output->fetch());
	}//end testSummariseAfterACleanApplyExitsZero()

	public function testSummariseAfterAFailedRepairExitsOne(): void {
		$this->assertSame(1, $this->pass(apply: true)->summarise(counts: ['tables' => 3, 'columns' => 5, 'repaired' => 1, 'failed' => 2]));
		$this->assertSame("Repaired 1/3 table(s); 2 failed.\n", $this->output->fetch());
	}//end testSummariseAfterAFailedRepairExitsOne()
}//end class
