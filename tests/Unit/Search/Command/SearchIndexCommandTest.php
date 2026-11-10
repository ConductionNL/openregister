<?php

/**
 * SearchIndexCommand dispatches each action and decides its exit code.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Command;

use OCA\OpenRegister\Search\Command\SearchIndexCommand;
use OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use OCA\OpenRegister\Search\Service\Search\SearchIndexPass;

/**
 * Behaviour of the `openregister:tables:search-index` command.
 */
class SearchIndexCommandTest extends TestCase {

	/**
	 * The maintenance service the command delegates to.
	 *
	 * @var SearchIndexMaintenance&MockObject
	 */
	private SearchIndexMaintenance&MockObject $maintenance;

	/**
	 * The console tester around the command.
	 *
	 * @var CommandTester
	 */
	private CommandTester $tester;

	protected function setUp(): void {
		$this->maintenance = $this->createMock(SearchIndexMaintenance::class);
		$this->tester = new CommandTester(new SearchIndexCommand($this->maintenance));
	}

	public function testConfiguresNameArgumentAndOptions(): void {
		$command = new SearchIndexCommand($this->maintenance);

		$this->assertSame('openregister:tables:search-index', $command->getName());
		$definition = $command->getDefinition();
		$this->assertTrue($definition->getArgument('action')->isRequired());
		$this->assertFalse($definition->getOption('apply')->acceptValue());
		$this->assertTrue($definition->getOption('register')->isValueRequired());
		$this->assertTrue($definition->getOption('file')->isValueRequired());
	}

	public function testRebuildDryRunPassesNoRegisterAndReportsDryRun(): void {
		$this->maintenance->expects($this->once())->method('rebuild')
			->with(null, $this->callback(static fn (SearchIndexPass $pass): bool => $pass->apply === false))
			->willReturnCallback(
				static function (?int $registerId, SearchIndexPass $pass): array {
					$pass->announce(['oc_or_r1_s1', 'idx_name', 1, 2]);
					return ['state' => 'dry-run', 'tables' => 1, 'indexes' => 2, 'rebuilt' => 0, 'failed' => 3];
				}
			);

		$code = $this->tester->execute(['action' => 'rebuild']);

		$display = $this->tester->getDisplay();
		$this->assertSame(0, $code);
		$this->assertStringContainsString('[1/2] oc_or_r1_s1 · idx_name', $display);
		$this->assertStringContainsString('Tables: 1, indexes: 2, rebuilt: 0', $display);
		$this->assertStringContainsString('Dry run. Nothing was rebuilt. Add --apply.', $display);
		$this->assertStringNotContainsString('still answering', $display);
	}

	public function testRebuildApplyCastsRegisterAndSucceedsWithoutFailures(): void {
		$this->maintenance->expects($this->once())->method('rebuild')
			->with(7, $this->callback(static fn (SearchIndexPass $pass): bool => $pass->apply === true))
			->willReturn(['state' => 'completed', 'tables' => 3, 'indexes' => 4, 'rebuilt' => 4, 'failed' => 0, 'failures' => []]);

		$code = $this->tester->execute(['action' => 'rebuild', '--apply' => true, '--register' => '7']);

		$this->assertSame(0, $code);
		$display = $this->tester->getDisplay();
		$this->assertStringContainsString('Tables: 3, indexes: 4, rebuilt: 4', $display);
		$this->assertStringNotContainsString('Dry run', $display);
	}

	public function testRebuildApplyWithFailuresListsThemAndExitsOne(): void {
		$this->maintenance->method('rebuild')->willReturn(
			[
				'state' => 'completed', 'tables' => 1, 'indexes' => 2, 'rebuilt' => 1, 'failed' => 1,
				'failures' => [['table' => 'oc_t', 'index' => 'idx_a', 'error' => 'lock timeout']],
			]
		);

		$code = $this->tester->execute(['action' => 'rebuild', '--apply' => true]);

		$this->assertSame(1, $code);
		$display = $this->tester->getDisplay();
		$this->assertStringContainsString('oc_t · idx_a: lock timeout', $display);
		$this->assertStringContainsString('The indexes that failed are unchanged and still answering.', $display);
	}

	public function testRebuildRefusedPrintsReasonAndExitsOne(): void {
		$this->maintenance->method('rebuild')->willReturn(['state' => 'refused', 'reason' => 'No concurrent reindex on sqlite.']);

		$code = $this->tester->execute(['action' => 'rebuild', '--apply' => true]);

		$this->assertSame(1, $code);
		$display = $this->tester->getDisplay();
		$this->assertStringContainsString('No concurrent reindex on sqlite.', $display);
		$this->assertStringNotContainsString('Tables:', $display);
	}

	public function testRebuildRefusedWithoutReasonFallsBackToRefused(): void {
		$this->maintenance->method('rebuild')->willReturn(['state' => 'refused']);

		$code = $this->tester->execute(['action' => 'rebuild']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('Refused.', $this->tester->getDisplay());
	}

	public function testSnapshotWithoutFileIsRefusedWithoutCallingTheService(): void {
		$this->maintenance->expects($this->never())->method('snapshot');

		$code = $this->tester->execute(['action' => 'snapshot']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('snapshot needs --file.', $this->tester->getDisplay());
	}

	public function testSnapshotWritesTheFileForTheRegister(): void {
		$this->maintenance->expects($this->once())->method('snapshot')
			->with('/tmp/snap.json', 4)
			->willReturn(['indexes' => 9, 'tables' => 2, 'path' => '/tmp/snap.json']);

		$code = $this->tester->execute(['action' => 'snapshot', '--file' => '/tmp/snap.json', '--register' => '4']);

		$this->assertSame(0, $code);
		$this->assertStringContainsString(
			'Wrote 9 index definitions over 2 tables to /tmp/snap.json',
			$this->tester->getDisplay()
		);
	}

	public function testRestoreWithoutFileIsRefusedWithoutCallingTheService(): void {
		$this->maintenance->expects($this->never())->method('restore');

		$code = $this->tester->execute(['action' => 'restore', '--apply' => true]);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('restore needs --file.', $this->tester->getDisplay());
	}

	public function testRestoreDryRunListsMissingIndexesAndExitsZero(): void {
		$this->maintenance->expects($this->once())->method('restore')
			->with('/tmp/s.json', false)
			->willReturn(
				[
					'missing' => 1, 'created' => 0, 'failed' => 1,
					'indexes' => [['table' => 'oc_t', 'index' => 'idx_b']],
				]
			);

		$code = $this->tester->execute(['action' => 'restore', '--file' => '/tmp/s.json']);

		$this->assertSame(0, $code);
		$display = $this->tester->getDisplay();
		$this->assertStringContainsString('Missing: 1, created: 0', $display);
		$this->assertStringContainsString('  oc_t · idx_b', $display);
		$this->assertStringContainsString('Dry run. Nothing was created. Add --apply.', $display);
	}

	public function testRestoreApplyWithFailuresExitsOne(): void {
		$this->maintenance->expects($this->once())->method('restore')
			->with('/tmp/s.json', true)
			->willReturn(
				[
					'missing' => 1, 'created' => 0, 'failed' => 1, 'indexes' => [],
					'failures' => [['table' => 'oc_t', 'index' => 'idx_b', 'error' => 'duplicate']],
				]
			);

		$code = $this->tester->execute(['action' => 'restore', '--file' => '/tmp/s.json', '--apply' => true]);

		$this->assertSame(1, $code);
		$display = $this->tester->getDisplay();
		$this->assertStringContainsString('oc_t · idx_b: duplicate', $display);
		$this->assertStringNotContainsString('Dry run', $display);
	}

	public function testRestoreApplyWithoutFailuresExitsZero(): void {
		$this->maintenance->method('restore')->willReturn(['missing' => 2, 'created' => 2]);

		$code = $this->tester->execute(['action' => 'restore', '--file' => '/tmp/s.json', '--apply' => true]);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('Missing: 2, created: 2', $this->tester->getDisplay());
	}

	public function testStatusWithoutRunSaysSo(): void {
		$this->maintenance->method('lastRun')->willReturn([]);

		$code = $this->tester->execute(['action' => 'status']);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('No rebuild has run yet.', $this->tester->getDisplay());
	}

	public function testStatusPrintsLastRunAsJson(): void {
		$this->maintenance->method('lastRun')->willReturn(['state' => 'completed', 'rebuilt' => 5]);

		$code = $this->tester->execute(['action' => 'status']);

		$this->assertSame(0, $code);
		$display = $this->tester->getDisplay();
		$this->assertStringNotContainsString('No rebuild has run yet.', $display);
		$this->assertSame(['state' => 'completed', 'rebuilt' => 5], json_decode($display, true));
	}

	public function testUnknownActionIsRefused(): void {
		$this->maintenance->expects($this->never())->method('rebuild');

		$code = $this->tester->execute(['action' => 'vacuum']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString(
			"Unknown action 'vacuum'. Use rebuild, snapshot, restore or status.",
			$this->tester->getDisplay()
		);
	}

	public function testThrowableFromTheServiceBecomesExitOne(): void {
		$this->maintenance->method('snapshot')->willThrowException(new RuntimeException('disk full'));

		$code = $this->tester->execute(['action' => 'snapshot', '--file' => '/tmp/x.json']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('disk full', $this->tester->getDisplay());
	}
}
