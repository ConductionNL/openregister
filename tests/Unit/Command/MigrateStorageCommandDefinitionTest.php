<?php

/**
 * Characterisation: the console definition of MigrateStorageCommand, pinned so a
 * restructuring of configure() cannot drift.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\MigrateStorageCommand;
use OCA\OpenRegister\Service\MigrationServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * The command's name, description, help, arguments and options.
 *
 * @covers \OCA\OpenRegister\Command\MigrateStorageCommand
 */
class MigrateStorageCommandDefinitionTest extends TestCase {

	/**
	 * The definition as plain data, in declaration order.
	 *
	 * @param MigrateStorageCommand $command The command.
	 *
	 * @return array<string, mixed> The dump.
	 */
	private function dump(MigrateStorageCommand $command): array {
		$definition = $command->getDefinition();
		$arguments = array_map(
			static fn (InputArgument $argument): array => [
				$argument->getName(),
				$argument->isRequired(),
				$argument->isArray(),
				$argument->getDescription(),
				$argument->getDefault(),
			],
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

		return [
			'name' => $command->getName(),
			'description' => $command->getDescription(),
			'helpSha256' => hash('sha256', $command->getHelp()),
			'arguments' => $arguments,
			'options' => $options,
		];
	}//end dump()

	/**
	 * The definition is exactly the one the command has always declared.
	 *
	 * @return void
	 */
	public function testTheDefinitionIsPinned(): void {
		$command = new MigrateStorageCommand($this->createMock(MigrationServiceInterface::class));

		$this->assertSame([
			'name' => 'openregister:migrate-storage',
			'description' => 'Migrate objects between blob storage and magic tables',
			'helpSha256' => '2b275b4a5d5a16f2fab11f879fd5881aa9ac33a55f223bd04332d26644a6bb6d',
			'arguments' => [
				[
					'direction',
					true,
					false,
					'Migration direction: to-magic or to-blob',
					null,
				],
				[
					'register',
					true,
					false,
					'Register ID or slug',
					null,
				],
				[
					'schema',
					true,
					false,
					'Schema ID or slug',
					null,
				],
			],
			'options' => [
				[
					'batch-size',
					'b',
					true,
					true,
					false,
					'Batch size for migration',
					'100',
				],
				[
					'dry-run',
					null,
					false,
					false,
					false,
					'Preview migration without making changes',
					false,
				],
				[
					'status',
					's',
					false,
					false,
					false,
					'Show storage status only',
					false,
				],
			],
		], $this->dump($command));
	}//end testTheDefinitionIsPinned()
}//end class
