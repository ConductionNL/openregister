<?php

/**
 * DedupeSharedSchemasCommand tells a split whose source table is absent apart from an empty one.
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

use OCA\OpenRegister\Command\DedupeSharedSchemasCommand;
use OCA\OpenRegister\Service\SharedSchema\SchemaAttribution;
use OCA\OpenRegister\Service\SharedSchemaDedupeService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Behaviour of the dry-run report for the row count of each split.
 */
class DedupeSharedSchemasCommandMissingTableTest extends TestCase {

	/**
	 * Run a dry run over a one-split plan whose table holds the given row count.
	 *
	 * @param int $rows Row count, or -1 for an absent table.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function dryRun(int $rows): array {
		$dedupe = $this->createMock(SharedSchemaDedupeService::class);
		$dedupe->method('parseKeep')->willReturn(['perSchema' => [], 'global' => null]);
		$dedupe->method('inspect')->willReturn(
			[
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
							'rows'         => $rows,
							'unmapped'     => [],
						],
					],
				],
			]
		);
		$dedupe->expects($this->never())->method('applySplit');

		$command = new DedupeSharedSchemasCommand($dedupe);
		$output  = new BufferedOutput();
		$code    = $command->run(new ArrayInput([], $command->getDefinition()), $output);
		return [$code, $output->fetch()];
	}

	public function testAnAbsentTableIsReportedAsNoTable(): void {
		[$code, $output] = $this->dryRun(-1);

		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('(openregister_table_16_161, no table)', $output);
		$this->assertStringNotContainsString('-1 row(s)', $output);
	}

	public function testAnEmptyTableIsReportedAsZeroRows(): void {
		[, $output] = $this->dryRun(0);

		$this->assertStringContainsString('(openregister_table_16_161, 0 row(s))', $output);
		$this->assertStringNotContainsString('no table', $output);
	}
}
