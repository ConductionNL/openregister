<?php

/**
 * Characterisation: the console definition of openregister:objects:purge,
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
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\PurgeObjectCommand;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * The command's name, description, arguments and options, in declaration order.
 */
class PurgeObjectCommandDefinitionTest extends TestCase {

	/**
	 * The definition is exactly the one the command has always declared.
	 *
	 * @return void
	 */
	public function testTheDefinitionIsPinned(): void {
		$command = new PurgeObjectCommand(
			new SchemaCatalog(
				$this->createMock(RegisterMapperInterface::class),
				$this->createMock(SchemaMapperInterface::class),
				$this->createMock(MagicMapperInterface::class)
			),
			$this->createMock(AuditTrailMapperInterface::class)
		);
		$definition = $command->getDefinition();

		$this->assertSame(
			[
				'openregister:objects:purge',
				'Permanently destroy object rows by UUID, including archival records with --force',
				[['uuid', false, true, 'One or more object UUIDs to purge (optional with --import-job)', []]],
				[
					['import-job', null, true, true, false, 'Also purge every object this import job created, such as an app\'s example set', null],
					['force', null, false, false, false, 'Also purge records on archival schemas, and records that are still live', false],
					['apply', null, false, false, false, 'Actually destroy the rows. Without it the command reports what it would do', false],
				],
			],
			[
				$command->getName(),
				$command->getDescription(),
				array_map(
					static fn (InputArgument $argument): array => [
						$argument->getName(),
						$argument->isRequired(),
						$argument->isArray(),
						$argument->getDescription(),
						$argument->getDefault(),
					],
					array_values($definition->getArguments())
				),
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
					array_values($definition->getOptions())
				),
			]
		);
	}//end testTheDefinitionIsPinned()
}//end class
