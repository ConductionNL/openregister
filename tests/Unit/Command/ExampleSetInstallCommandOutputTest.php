<?php

/**
 * Characterisation: the console definition of example-set:install and the
 * report it prints after an install, pinned before configure() and
 * printReport() are restructured.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\ExampleSetInstallCommand;
use OCA\OpenRegister\Kernel\Contracts\ExampleSetInstallerInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogueInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The definition, and the report for a full and an empty install.
 */
class ExampleSetInstallCommandOutputTest extends TestCase {

	/**
	 * The command over inert doubles.
	 *
	 * @return ExampleSetInstallCommand The command.
	 */
	private function command(): ExampleSetInstallCommand {
		return new ExampleSetInstallCommand(
			catalogue: $this->createMock(ExampleSetCatalogueInterface::class),
			installer: $this->createMock(ExampleSetInstallerInterface::class)
		);
	}//end command()

	/**
	 * The report printReport() prints for an installer report.
	 *
	 * @param array<string, mixed> $report The installer's report.
	 *
	 * @return string The display.
	 */
	private function printed(array $report): string {
		$output = new BufferedOutput();
		$method = new ReflectionMethod(ExampleSetInstallCommand::class, 'printReport');
		$method->invoke($this->command(), $report, $output);

		return $output->fetch();
	}//end printed()

	/**
	 * The definition is exactly the one the command has always declared.
	 *
	 * @return void
	 */
	public function testTheDefinitionIsPinned(): void {
		$command = $this->command();
		$definition = $command->getDefinition();
		$arguments = array_map(
			static fn (InputArgument $argument): array => [$argument->getName(), $argument->isRequired(), $argument->isArray(), $argument->getDescription()],
			array_values($definition->getArguments())
		);
		$options = array_map(
			static fn (InputOption $option): array => [
				$option->getName(),
				$option->getShortcut(),
				$option->acceptValue(),
				$option->isValueRequired(),
				$option->isArray(),
				$option->getDescription(),
				$option->getDefault(),
			],
			array_values($definition->getOptions())
		);

		$this->assertSame(
			json_decode((string)file_get_contents(__DIR__ . '/fixtures/example-set-install-definition.json'), true, 512, JSON_THROW_ON_ERROR),
			['name' => $command->getName(), 'description' => $command->getDescription(), 'arguments' => $arguments, 'options' => $options]
		);
	}//end testTheDefinitionIsPinned()

	/**
	 * Every part of a full report is printed, in order.
	 *
	 * @return void
	 */
	public function testAFullReportIsPrintedInOrder(): void {
		$report = [
			'types' => ['brp person' => ['declared' => 2, 'created' => 1, 'kept' => 1, 'arrived' => 2, 'refreshed' => 1]],
			'files' => 3,
			'left' => ['a note'],
			'dropped' => ['x'],
			'missing' => ['y'],
			'lost' => ['z'],
			'passwords' => ['sanne' => 'pw-1', 'jan' => 'pw-2'],
		];
		$words = [
			'registers', 'groups', 'users', 'memberships', 'changes', 'appConfig', 'userPreferences',
			'tasks', 'views', 'watchers', 'parties', 'notes', 'readStates', 'flows',
		];
		foreach ($words as $index => $part) {
			$report[$part] = [$part . '-a' => 'created', $part . '-b' => 'kept'];
			if ($index % 2 === 0) {
				$report[$part][$part . '-c'] = 'adopted';
			}
		}

		$this->assertSame(
			file_get_contents(__DIR__ . '/fixtures/example-set-install-report.txt'),
			$this->printed($report)
		);
	}//end testAFullReportIsPrintedInOrder()

	/**
	 * An empty report prints only its types, and no files or passwords lines.
	 *
	 * @return void
	 */
	public function testAnEmptyReportPrintsOnlyItsTypes(): void {
		$report = [
			'types' => ['brp person' => ['declared' => 0, 'created' => 0, 'kept' => 0, 'arrived' => 0]],
			'files' => 0,
			'left' => [],
			'dropped' => [],
			'missing' => [],
			'lost' => [],
			'passwords' => [],
		];

		$this->assertSame("brp person: 0 declared, 0 created, 0 already there, 0 found afterwards\n", $this->printed($report));
	}//end testAnEmptyReportPrintsOnlyItsTypes()
}//end class
