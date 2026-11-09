<?php

/**
 * Tests for `occ openregister:example-set:list` against stubbed collaborators:
 * no sets, installed and not-installed sets, and the exact record lookups.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\ExampleSetListCommand;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogueInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecordInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Drives the list command through CommandTester.
 */
class ExampleSetListCommandBehaviourTest extends TestCase {

	public function testNoSetsPrintsTheNoticeAndNeverReadsARecord(): void {
		$catalogue = $this->createMock(ExampleSetCatalogueInterface::class);
		$catalogue->expects($this->once())->method('sets')->willReturn([]);
		$record = $this->createMock(ExampleSetRecordInterface::class);
		$record->expects($this->never())->method('read');
		$record->expects($this->never())->method('isEmpty');

		$tester = new CommandTester(new ExampleSetListCommand(catalogue: $catalogue, record: $record));

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame("No enabled app ships an example set.\n", $tester->getDisplay());
	}

	public function testEachSetIsListedWithItsStateAndApps(): void {
		$catalogue = $this->createMock(ExampleSetCatalogueInterface::class);
		$catalogue->expects($this->once())->method('sets')->willReturn(
			[
				'demo'  => ['alpha', 'openregister'],
				'other' => ['beta'],
			]
		);

		$demoRecord  = ['users' => ['sanne' => 'created']];
		$otherRecord = ['users' => []];

		$record = $this->createMock(ExampleSetRecordInterface::class);
		$record->expects($this->exactly(2))->method('read')->willReturnCallback(
			static fn (string $set): array => ['demo' => $demoRecord, 'other' => $otherRecord][$set]
		);
		$record->expects($this->exactly(2))->method('isEmpty')->willReturnCallback(
			static fn (array $r): bool => $r === $otherRecord
		);

		$tester = new CommandTester(new ExampleSetListCommand(catalogue: $catalogue, record: $record));

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame(
			"demo (installed): alpha, openregister\nother (not installed): beta\n",
			$tester->getDisplay()
		);
	}

	public function testCommandIsRegisteredUnderItsName(): void {
		$command = new ExampleSetListCommand(
			catalogue: $this->createMock(ExampleSetCatalogueInterface::class),
			record: $this->createMock(ExampleSetRecordInterface::class)
		);

		$this->assertSame('openregister:example-set:list', $command->getName());
		$this->assertSame(
			'List the example sets the enabled apps ship, and whether each is installed',
			$command->getDescription()
		);
	}
}//end class
