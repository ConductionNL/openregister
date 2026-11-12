<?php

/**
 * Behaviour tests for `occ openregister:example-set:remove`: exit codes, the
 * not-installed early return, and how each `left` and `failed` line is worded.
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

use OCA\OpenRegister\Command\ExampleSetRemoveCommand;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command over a mocked remover: only the report shape crosses the boundary.
 */
class ExampleSetRemoveCommandBehaviourTest extends TestCase {

	/**
	 * Build a tester whose remover expects exactly one call for $set and returns $report.
	 *
	 * @param string               $set    The set id the remover must receive.
	 * @param array<string, mixed> $report The remover's report.
	 *
	 * @return CommandTester
	 */
	private function tester(string $set, array $report): CommandTester {
		$remover = $this->createMock(ExampleSetRemoverInterface::class);
		$remover->expects($this->once())
			->method('remove')
			->with($set)
			->willReturn($report);

		return new CommandTester(new ExampleSetRemoveCommand(remover: $remover));
	}

	public function testANotInstalledSetExitsZeroAndPrintsNothingElse(): void {
		$tester = $this->tester('demo', ['installed' => false, 'removed' => ['objects' => 3], 'left' => ['x'], 'failed' => ['y']]);

		$this->assertSame(0, $tester->execute(['set' => '  demo  ']));
		$this->assertSame("Example set demo is not installed on this instance; nothing to remove.\n", $tester->getDisplay());
	}

	public function testAFullRemovalListsEachKindAndExitsZero(): void {
		$tester = $this->tester('demo', ['installed' => true, 'removed' => ['objects' => 4, 'groups' => 1], 'left' => [], 'failed' => []]);

		$this->assertSame(0, $tester->execute(['set' => 'demo']));
		$this->assertSame("Removed objects: 4\nRemoved groups: 1\n", $tester->getDisplay());
	}

	public function testAnInstalledSetWithNothingRecordedExitsZeroSilently(): void {
		$tester = $this->tester('demo', ['installed' => true, 'removed' => [], 'left' => [], 'failed' => []]);

		$this->assertSame(0, $tester->execute(['set' => 'demo']));
		$this->assertSame('', $tester->getDisplay());
	}

	public function testEachNotePrefixIsPrintedAsItsOwnSentence(): void {
		$left = [
			'app config openregister/foo',
			'user preference sanne/theme',
			'OpenRegister organisation Gemeente X',
			'change 42 on brp person',
		];
		$tester = $this->tester('demo', ['installed' => true, 'removed' => [], 'left' => $left, 'failed' => []]);

		$this->assertSame(0, $tester->execute(['set' => 'demo']));
		$expected = '';
		foreach ($left as $line) {
			$expected .= 'Left in place: '.$line.".\n";
		}

		$this->assertSame($expected, $tester->getDisplay());
	}

	public function testAnyOtherLeftLineIsAnAdoptedAccount(): void {
		// "change" without the trailing space is not the note prefix: it is an account id.
		$tester = $this->tester('demo', ['installed' => true, 'removed' => [], 'left' => ['sanne.devries', 'changeling'], 'failed' => []]);

		$this->assertSame(0, $tester->execute(['set' => 'demo']));
		$this->assertSame(
			"Left in place: account sanne.devries, which the set adopted and did not create.\n"
			."Left in place: account changeling, which the set adopted and did not create.\n",
			$tester->getDisplay()
		);
	}

	public function testAFailureNamesEachLineAndExitsTwo(): void {
		$tester = $this->tester('demo', ['installed' => true, 'removed' => ['objects' => 1], 'left' => [], 'failed' => ['brp person a', 'group g']]);

		$this->assertSame(2, $tester->execute(['set' => 'demo']));
		$this->assertSame(
			"Removed objects: 1\n"
			."Not removed: brp person a\n"
			."Not removed: group g\n"
			."Some of the set is still here and stays in its record; run remove again once the cause is fixed.\n",
			$tester->getDisplay()
		);
	}
}
