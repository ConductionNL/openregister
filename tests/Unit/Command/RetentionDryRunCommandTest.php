<?php

/**
 * occ openregister:retention:dry-run prints what would be listed and what is held back.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Command\RetentionDryRunCommand;
use OCA\OpenRegister\Service\Archival\DestructionDryRun;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RetentionDryRunCommand::class)]
class RetentionDryRunCommandTest extends TestCase {

	private const RESULT = [
		'eligible'  => [['uuid' => 'pub-4', 'name' => 'Besluit 4', 'schema' => '7']],
		'heldBack'  => [
			[
				'uuid'     => 'pub-1',
				'name'     => 'Besluit 1',
				'schema'   => '7',
				'reason'   => 'inherited_permanent_appraisal',
				'ancestor' => ['uuid' => 'subject-1', 'name' => 'Stikstof', 'appraisal' => 'blijvend_bewaren', 'property' => null],
			],
			[
				'uuid'     => 'pub-2',
				'name'     => '',
				'schema'   => '7',
				'reason'   => 'unresolved_ancestor',
				'ancestor' => ['uuid' => 'gone', 'name' => null, 'appraisal' => null, 'property' => 'subjects'],
			],
		],
		'scanned'   => 3,
		'truncated' => true,
	];

	private function tester(): CommandTester {
		$dryRun = $this->createMock(DestructionDryRun::class);
		$dryRun->expects($this->once())->method('run')->willReturn(self::RESULT);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(DestructionDryRun::class)->willReturn($dryRun);

		return new CommandTester(new RetentionDryRunCommand($container));
	}

	public function testItNamesTheAncestorAndItsAppraisal(): void {
		$tester = $this->tester();

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame('openregister:retention:dry-run', RetentionDryRunCommand::NAME);
		$this->assertSame(
			"Would go on a destruction list: 1. Nothing was written.\n"
			."  Besluit 4 (pub-4)\n"
			."Held back by an inherited appraisal: 2.\n"
			."  Besluit 1 (pub-1): held by its ancestor Stikstof (subject-1), appraisal blijvend_bewaren\n"
			."  (unnamed) (pub-2): the ancestor gone in \"subjects\" could not be read\n"
			."The scan stopped after 3 objects; the next run continues where it left off.\n",
			$tester->getDisplay()
		);
	}

	public function testJsonPrintsTheWholeResult(): void {
		$tester = $this->tester();

		$this->assertSame(0, $tester->execute(['--json' => true]));
		$this->assertSame(self::RESULT, json_decode($tester->getDisplay(), true));
	}
}
