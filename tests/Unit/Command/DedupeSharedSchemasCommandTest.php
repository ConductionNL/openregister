<?php

/**
 * Unit tests for the dedupe-shared-schemas command surface (#2689).
 *
 * The safety rails are the point of this command, so they are what is asserted:
 * dry run by default, an explicit refusal to write an unattributed schema, and
 * `--strict` passed through to the row move. A repair that mutates schema
 * linkage and moves data tables must be provably inert until asked.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
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

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\DedupeSharedSchemasCommand;
use OCA\OpenRegister\Service\SharedSchema\SchemaAttribution;
use OCA\OpenRegister\Service\SharedSchemaDedupeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Locks the console surface of `occ openregister:registers:dedupe-shared-schemas`.
 */
class DedupeSharedSchemasCommandTest extends TestCase {

	/**
	 * The mocked repair service.
	 *
	 * @var SharedSchemaDedupeService&MockObject
	 */
	private SharedSchemaDedupeService&MockObject $dedupe;

	/**
	 * The command under test.
	 *
	 * @var DedupeSharedSchemasCommand
	 */
	private DedupeSharedSchemasCommand $command;

	/**
	 * Wire the command onto a mocked service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dedupe = $this->createMock(SharedSchemaDedupeService::class);
		$this->dedupe->method('parseKeep')->willReturn(['perSchema' => [], 'global' => null]);
		$this->command = new DedupeSharedSchemasCommand($this->dedupe);

	}//end setUp()

	/**
	 * Run the command and capture its exit code and output.
	 *
	 * @param array<string, mixed> $args The console arguments.
	 *
	 * @return array{0: int, 1: string} The exit code and the rendered output.
	 */
	private function execute(array $args): array {
		$input  = new ArrayInput($args, $this->command->getDefinition());
		$output = new BufferedOutput();
		$code   = $this->command->run($input, $output);

		return [$code, $output->fetch()];

	}//end execute()

	/**
	 * One attributed plan entry: register 16 splits off shared schema 161.
	 *
	 * @return array<int, array<string, mixed>> The plan.
	 */
	private function attributedPlan(): array {
		return [
			[
				'schemaId'    => 161,
				'schemaSlug'  => 'timeEntry',
				'registerIds' => [16, 19],
				'status'      => SchemaAttribution::STATUS_ONE_MATCH,
				'matches'     => [19],
				'owner'       => 19,
				'ownerSource' => 'configuration',
				'splits'      => [
					16 => [
						'registerSlug' => 'pipelinq',
						'application'  => 'pipelinq',
						'path'         => 'configuration',
						'definition'   => ['slug' => 'timeEntry'],
						'table'        => 'openregister_table_16_161',
						'rows'         => 42,
						'unmapped'     => ['employee'],
					],
				],
			],
		];

	}//end attributedPlan()

	/**
	 * The same plan, but attribution could not settle an owner.
	 *
	 * @return array<int, array<string, mixed>> The plan.
	 */
	private function unattributedPlan(): array {
		$plan                = $this->attributedPlan();
		$plan[0]['status']   = SchemaAttribution::STATUS_MULTI_MATCH;
		$plan[0]['matches']  = [16, 19];
		$plan[0]['owner']    = null;
		$plan[0]['ownerSource'] = 'unattributed';

		return $plan;

	}//end unattributedPlan()

	/**
	 * MUST-FAIL CONTROL: a healthy instance reports nothing and writes nothing.
	 *
	 * @return void
	 */
	public function testHealthyInstanceReportsNothing(): void {
		$this->dedupe->method('inspect')->willReturn([]);
		$this->dedupe->expects($this->never())->method('applySplit');

		[$code, $output] = $this->execute(['--write' => true]);

		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('No schema is shared by more than one register', $output);

	}//end testHealthyInstanceReportsNothing()

	/**
	 * Without `--write` the command reports and changes nothing.
	 *
	 * @return void
	 */
	public function testDryRunIsTheDefaultAndAppliesNothing(): void {
		$this->dedupe->method('inspect')->willReturn($this->attributedPlan());
		$this->dedupe->expects($this->never())->method('applySplit');

		[$code, $output] = $this->execute([]);

		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('1 schema(s) are shared by more than one register', $output);
		$this->assertStringContainsString('schema 161 (timeEntry)', $output);
		$this->assertStringContainsString('owner: register 19 (configuration)', $output);
		$this->assertStringContainsString('register 16 (pipelinq)', $output);
		$this->assertStringContainsString('42 row(s)', $output);
		$this->assertStringContainsString('DRY RUN', $output);

	}//end testDryRunIsTheDefaultAndAppliesNothing()

	/**
	 * The dry run names the columns that would be left behind.
	 *
	 * @return void
	 */
	public function testDryRunNamesTheColumnsWithNoDestination(): void {
		$this->dedupe->method('inspect')->willReturn($this->attributedPlan());

		[, $output] = $this->execute([]);

		$this->assertStringContainsString('would have no destination: employee', $output);

	}//end testDryRunNamesTheColumnsWithNoDestination()

	/**
	 * MUST-PASS CONTROL: `--write` on an attributed plan performs the split.
	 *
	 * @return void
	 */
	public function testWriteAppliesTheSplit(): void {
		$this->dedupe->method('inspect')->willReturn($this->attributedPlan());
		$this->dedupe->expects($this->once())
			->method('applySplit')
			->with($this->anything(), 16, false)
			->willReturn([
				'newSchemaId' => 9465,
				'rows'        => 42,
				'unmapped'    => ['employee'],
				'backup'      => 'oc_openregister_table_16_161_predupe',
			]);

		[$code, $output] = $this->execute(['--write' => true]);

		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('split', $output);
		$this->assertStringContainsString('schema 161 -> 9465', $output);
		$this->assertStringContainsString('42 row(s) moved', $output);
		$this->assertStringContainsString('oc_openregister_table_16_161_predupe', $output);
		$this->assertStringContainsString('1 split(s) applied; 0 failure(s)', $output);

	}//end testWriteAppliesTheSplit()

	/**
	 * `--write` REFUSES an unattributed schema rather than guessing an owner.
	 *
	 * This is the rail the whole command exists behind: picking a side by heuristic
	 * is what produced the damage being repaired.
	 *
	 * @return void
	 */
	public function testWriteRefusesAnUnattributedSchema(): void {
		$this->dedupe->method('inspect')->willReturn($this->unattributedPlan());
		$this->dedupe->expects($this->never())->method('applySplit');

		[$code, $output] = $this->execute(['--write' => true]);

		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('UNATTRIBUTED', $output);
		$this->assertStringContainsString('Refusing to write', $output);
		$this->assertStringContainsString('--keep', $output);

	}//end testWriteRefusesAnUnattributedSchema()

	/**
	 * A dry run over an unattributed schema says it would be skipped, and succeeds.
	 *
	 * @return void
	 */
	public function testDryRunAnnouncesTheSkipWithoutFailing(): void {
		$this->dedupe->method('inspect')->willReturn($this->unattributedPlan());

		[$code, $output] = $this->execute([]);

		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('would be SKIPPED', $output);

	}//end testDryRunAnnouncesTheSkipWithoutFailing()

	/**
	 * `--strict` reaches the row move.
	 *
	 * @return void
	 */
	public function testStrictIsPassedThroughToTheSplit(): void {
		$this->dedupe->method('inspect')->willReturn($this->attributedPlan());
		$this->dedupe->expects($this->once())
			->method('applySplit')
			->with($this->anything(), 16, true)
			->willReturn(['newSchemaId' => 9465, 'rows' => 0, 'unmapped' => [], 'backup' => null]);

		[$code] = $this->execute(['--write' => true, '--strict' => true]);

		$this->assertSame(Command::SUCCESS, $code);

	}//end testStrictIsPassedThroughToTheSplit()

	/**
	 * A failed split is reported and turns the exit code non-zero.
	 *
	 * A repair that swallowed a failure would leave the operator believing the
	 * instance was clean.
	 *
	 * @return void
	 */
	public function testFailedSplitIsReportedAndFailsTheRun(): void {
		$this->dedupe->method('inspect')->willReturn($this->attributedPlan());
		$this->dedupe->method('applySplit')->willThrowException(
			new \RuntimeException('Strict mode: 1 source column(s) have no destination (employee).')
		);

		[$code, $output] = $this->execute(['--write' => true, '--strict' => true]);

		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('failed', $output);
		$this->assertStringContainsString('no destination', $output);
		$this->assertStringContainsString('0 split(s) applied; 1 failure(s)', $output);

	}//end testFailedSplitIsReportedAndFailsTheRun()

	/**
	 * The `--register` filter reaches the service.
	 *
	 * @return void
	 */
	public function testRegisterFilterIsPassedThrough(): void {
		$this->dedupe->expects($this->once())
			->method('inspect')
			->with(16, $this->anything())
			->willReturn([]);

		[$code] = $this->execute(['--register' => '16']);

		$this->assertSame(Command::SUCCESS, $code);

	}//end testRegisterFilterIsPassedThrough()
}//end class
