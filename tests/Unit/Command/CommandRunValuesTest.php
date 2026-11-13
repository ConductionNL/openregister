<?php

/**
 * The small per-run value objects of the maintenance commands: the
 * application-id rename, the reset-to-shipped arguments and the magic-table
 * run tally with its dry-run note.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Command\ApplicationIdRename;
use OCA\OpenRegister\Command\MagicTableRun;
use OCA\OpenRegister\Command\ResetToShippedRun;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * ApplicationIdRename, ResetToShippedRun and MagicTableRun.
 *
 * @covers \OCA\OpenRegister\Command\ApplicationIdRename
 * @covers \OCA\OpenRegister\Command\ResetToShippedRun
 * @covers \OCA\OpenRegister\Command\MagicTableRun
 */
class CommandRunValuesTest extends TestCase {

	public function testTheRenameIsReadFromTheFromAndToArguments(): void {
		$definition = new InputDefinition([new InputArgument('from'), new InputArgument('to')]);
		$rename     = ApplicationIdRename::fromInput(new ArrayInput(['from' => 'procest', 'to' => 'dossiq'], $definition));

		$this->assertSame('procest', $rename->from);
		$this->assertSame('dossiq', $rename->to);
	}//end testTheRenameIsReadFromTheFromAndToArguments()

	public function testAMissingRenameArgumentReadsAsEmpty(): void {
		$definition = new InputDefinition([new InputArgument('from'), new InputArgument('to')]);
		$rename     = ApplicationIdRename::fromInput(new ArrayInput(['from' => 'a'], $definition));

		$this->assertSame('', $rename->to);
	}//end testAMissingRenameArgumentReadsAsEmpty()

	public function testTheMoveIsDescribedWithBothCountsAndBothIds(): void {
		$rename = new ApplicationIdRename(from: 'softwarecatalog', to: 'stackiq');

		$this->assertSame(
			'3 schema(s) and 1 register(s) from "softwarecatalog" to "stackiq"',
			$rename->describeMove(schemas: 3, registers: 1)
		);
	}//end testTheMoveIsDescribedWithBothCountsAndBothIds()

	private function resetInput(array $parameters): ArrayInput {
		$definition = new InputDefinition(
			[
				new InputArgument('schema'),
				new InputArgument('part'),
				new InputOption('actor', null, InputOption::VALUE_REQUIRED),
			]
		);

		return new ArrayInput($parameters, $definition);
	}//end resetInput()

	public function testTheResetRunReadsSchemaPartAndTrimmedActor(): void {
		$run = new ResetToShippedRun($this->resetInput(['schema' => 'case', 'part' => 'authorization', '--actor' => '  admin  ']), new BufferedOutput());

		$this->assertSame('case', $run->schema());
		$this->assertSame('authorization', $run->part());
		$this->assertSame('admin', $run->actor());
	}//end testTheResetRunReadsSchemaPartAndTrimmedActor()

	public function testTheResetRunActorIsEmptyWhenNotGiven(): void {
		$run = new ResetToShippedRun($this->resetInput(['schema' => '12', 'part' => 'all']), new BufferedOutput());

		$this->assertSame('', $run->actor());
		$this->assertSame('12', $run->schema());
	}//end testTheResetRunActorIsEmptyWhenNotGiven()

	private function magicRun(bool $dryRun): MagicTableRun {
		$definition = new InputDefinition([new InputOption('dry-run', null, InputOption::VALUE_NONE)]);
		$parameters = [];
		if ($dryRun === true) {
			$parameters['--dry-run'] = true;
		}

		return new MagicTableRun(new ArrayInput($parameters, $definition), new BufferedOutput());
	}//end magicRun()

	public function testADryRunCarriesItsNote(): void {
		$run = $this->magicRun(dryRun: true);

		$this->assertTrue($run->isDryRun());
		$this->assertSame(' (dry run)', $run->dryRunNote(' (dry run)'));
	}//end testADryRunCarriesItsNote()

	public function testARealRunHasNoNoteAndStartsAtZero(): void {
		$run = $this->magicRun(dryRun: false);

		$this->assertFalse($run->isDryRun());
		$this->assertSame('', $run->dryRunNote(' (dry run)'));
		$this->assertSame([0, 0, 0, 0, [], []], [$run->tables, $run->scanned, $run->changed, $run->failed, $run->registers, $run->schemas]);
	}//end testARealRunHasNoNoteAndStartsAtZero()
}//end class
