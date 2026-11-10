<?php

/**
 * Characterisation: the console definition of PruneRetiredSchemasCommand, pinned so a
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

use OCA\OpenRegister\Command\PruneRetiredSchemasCommand;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Service\SchemaTeardownInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * The command's name, description, help, arguments and options.
 *
 * @covers \OCA\OpenRegister\Command\PruneRetiredSchemasCommand
 */
class PruneRetiredSchemasCommandDefinitionTest extends TestCase {

	/**
	 * The definition as plain data, in declaration order.
	 *
	 * @param PruneRetiredSchemasCommand $command The command.
	 *
	 * @return array<string, mixed> The dump.
	 */
	private function dump(PruneRetiredSchemasCommand $command): array {
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
		$command = new PruneRetiredSchemasCommand($this->createMock(SchemaCatalogInterface::class), $this->createMock(SchemaTeardownInterface::class));

		$this->assertSame([
			'name' => 'openregister:schemas:prune-retired',
			'description' => 'Remove schemas an app has retired from its register descriptor (the import unions ids, so it never removes them itself).',
			'helpSha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
			'arguments' => [
			],
			'options' => [
				[
					'app',
					null,
					true,
					true,
					false,
					'The owning application id, exactly as it appears in the schema\'s `application` column (for example `filinq`). Scoping is mandatory: it is what stops this command reaching a same-slug schema another app owns.',
					null,
				],
				[
					'slug',
					null,
					true,
					true,
					true,
					'A retired schema slug. Repeat the option to prune several.',
					[
					],
				],
				[
					'apply',
					null,
					false,
					false,
					false,
					'Actually delete. Without this flag the command reports what it WOULD delete.',
					false,
				],
				[
					'force',
					null,
					false,
					false,
					false,
					'Also delete a schema that still owns objects. DANGEROUS: it drops those objects. Has no effect without --apply.',
					false,
				],
				[
					'force-archival',
					null,
					false,
					false,
					false,
					'Also delete a schema declaring x-openregister-archival, destroying records the operator may be legally required to retain. Deliberately separate from --force: an operator pruning a retired schema that happens to hold a few leftover rows has said nothing about retained records, and this flag is where they say it. Requires --force and --apply.',
					false,
				],
				[
					'force-kept-rows',
					null,
					false,
					false,
					false,
					'Also delete a schema holding frozen, archived or locked rows, or rows of a register that no longer exists. Deliberately separate from --force, and written to the audit trail as a forced removal. Requires --force and --apply.',
					false,
				],
			],
		], $this->dump($command));
	}//end testTheDefinitionIsPinned()
}//end class
