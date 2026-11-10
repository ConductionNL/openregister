<?php

/**
 * Characterisation: the console definitions of the register repair commands
 * (relink-schemas and dedupe-shared-schemas), pinned so a restructuring of
 * their configure() cannot drift.
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
 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\DedupeSharedSchemasCommand;
use OCA\OpenRegister\Command\RelinkRegisterSchemasCommand;
use OCA\OpenRegister\Service\RegisterSchemaLinkageRepairServiceInterface;
use OCA\OpenRegister\Service\SharedSchemaDedupeServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Each command's name, description, help and options, in declaration order.
 */
class RegisterRepairCommandDefinitionTest extends TestCase {

	/**
	 * The definition as plain data.
	 *
	 * @param Command $command The command.
	 *
	 * @return array<string, mixed> The dump.
	 */
	private function dump(Command $command): array {
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
			array_values($command->getDefinition()->getOptions())
		);

		return [
			'name' => $command->getName(),
			'description' => $command->getDescription(),
			'help' => $command->getHelp(),
			'arguments' => array_keys($command->getDefinition()->getArguments()),
			'options' => $options,
		];
	}//end dump()

	/**
	 * The golden definitions, keyed by command class.
	 *
	 * @return array<string, mixed> The goldens.
	 */
	private function goldens(): array {
		$json = file_get_contents(__DIR__ . '/fixtures/register-repair-command-definitions.json');

		return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
	}//end goldens()

	/**
	 * relink-schemas keeps its definition.
	 *
	 * @return void
	 */
	public function testRelinkSchemasDefinitionIsPinned(): void {
		$command = new RelinkRegisterSchemasCommand($this->createMock(RegisterSchemaLinkageRepairServiceInterface::class));

		$this->assertSame($this->goldens()['relink'], $this->dump($command));
	}//end testRelinkSchemasDefinitionIsPinned()

	/**
	 * dedupe-shared-schemas keeps its definition.
	 *
	 * @return void
	 */
	public function testDedupeSharedSchemasDefinitionIsPinned(): void {
		$command = new DedupeSharedSchemasCommand($this->createMock(SharedSchemaDedupeServiceInterface::class));

		$this->assertSame($this->goldens()['dedupe'], $this->dump($command));
	}//end testDedupeSharedSchemasDefinitionIsPinned()
}//end class
