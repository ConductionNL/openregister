<?php

/**
 * The dry-run-by-default options a register repair command declares, and how
 * it reads them back.
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

use OCA\OpenRegister\Command\RegisterRepairOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * RegisterRepairOptions.
 *
 * @covers \OCA\OpenRegister\Command\RegisterRepairOptions
 */
class RegisterRepairOptionsTest extends TestCase {

	private function command(): Command {
		$command = new Command('openregister:test-repair');
		RegisterRepairOptions::declareOn($command, 'Only repair this register (id)');

		return $command;
	}//end command()

	private function input(array $parameters): InputInterface {
		$input = new ArrayInput($parameters, $this->command()->getDefinition());

		return $input;
	}//end input()

	public function testWriteThenRegisterAreDeclaredInThatOrder(): void {
		$options = $this->command()->getDefinition()->getOptions();

		$this->assertSame(['write', 'register'], array_keys($options));
		$this->assertFalse($options['write']->acceptValue());
		$this->assertSame('Apply the changes. Without this flag nothing is modified.', $options['write']->getDescription());
		$this->assertTrue($options['register']->isValueRequired());
		$this->assertSame('Only repair this register (id)', $options['register']->getDescription());
	}//end testWriteThenRegisterAreDeclaredInThatOrder()

	public function testWriteIsOffByDefault(): void {
		$this->assertFalse($this->input([])->getOption('write'));
		$this->assertTrue($this->input(['--write' => true])->getOption('write'));
	}//end testWriteIsOffByDefault()

	public function testNoRegisterOptionMeansNoRegisterId(): void {
		$this->assertNull(RegisterRepairOptions::registerId($this->input([])));
	}//end testNoRegisterOptionMeansNoRegisterId()

	public function testTheRegisterOptionIsReadAsAnInteger(): void {
		$this->assertSame(17, RegisterRepairOptions::registerId($this->input(['--register' => '17'])));
	}//end testTheRegisterOptionIsReadAsAnInteger()

	public function testTheDryRunNoticeTellsHowToApply(): void {
		$output = new BufferedOutput();
		RegisterRepairOptions::writeDryRunNotice($output);

		$this->assertSame("DRY RUN — nothing was changed. Re-run with --write to apply.\n", $output->fetch());
	}//end testTheDryRunNoticeTellsHowToApply()
}//end class
