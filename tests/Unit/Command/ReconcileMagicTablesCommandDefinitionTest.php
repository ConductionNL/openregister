<?php

/**
 * Characterisation: the console definition of openregister:tables:reconcile,
 * pinned so a restructuring of configure() cannot drift.
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
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\ReconcileMagicTablesCommand;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputOption;

/**
 * The command's name, description and options, in declaration order.
 */
class ReconcileMagicTablesCommandDefinitionTest extends TestCase {

	/**
	 * The definition is exactly the one the command has always declared.
	 *
	 * @return void
	 */
	public function testTheDefinitionIsPinned(): void {
		$command = new ReconcileMagicTablesCommand(
			$this->createMock(RegisterMapperInterface::class),
			$this->createMock(MagicMapperInterface::class)
		);

		$this->assertSame(
			[
				'openregister:tables:reconcile',
				'Detect and repair magic-table column drift: physical columns missing for schema properties that were added to an existing schema without a subsequent write, and metadata columns (_archived, _frozen, _status, ...) missing from any magic table.',
				[],
				[
					['apply', null, false, false, false, 'Create the missing columns. Without this flag the command runs in dry-run mode and only reports the drift it would repair.', false],
					['register', null, true, true, false, 'Limit to a single register id (default: every register).', null],
				],
			],
			[
				$command->getName(),
				$command->getDescription(),
				array_keys($command->getDefinition()->getArguments()),
				array_map(
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
				),
			]
		);
	}//end testTheDefinitionIsPinned()
}//end class
