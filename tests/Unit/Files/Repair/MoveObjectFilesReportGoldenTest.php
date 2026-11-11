<?php

/**
 * Characterisation of how the object file move reports its tally: the repair step's output
 * and warning, and the occ command's lines and exit code, for a move, a refusal and a failure.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Repair;

use OCA\OpenRegister\Files\Command\MoveObjectFilesCommand;
use OCA\OpenRegister\Files\Repair\MoveObjectFilesToOpenRegisterAccount;
use OCA\OpenRegister\Files\Service\File\ObjectFileMigration;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Golden reports of the object file move.
 *
 * @covers \OCA\OpenRegister\Files\Repair\MoveObjectFilesToOpenRegisterAccount
 * @covers \OCA\OpenRegister\Files\Command\MoveObjectFilesCommand
 */
final class MoveObjectFilesReportGoldenTest extends TestCase {

	/**
	 * A tally.
	 *
	 * @param bool $refused Whether the storage refused the move.
	 *
	 * @return array<string, int|bool>
	 */
	private static function tally(bool $refused): array {
		return ['refused' => $refused, 'foldersMoved' => 2, 'filesMoved' => 5, 'sharesReowned' => 1, 'foldersLeft' => 3];
	}//end tally()

	/**
	 * A migration that answers a tally or throws.
	 *
	 * @param array<string, int|bool>|null $tally The tally, or null to throw.
	 *
	 * @return ObjectFileMigration
	 */
	private function migration(?array $tally): ObjectFileMigration {
		$migration = $this->createMock(ObjectFileMigration::class);
		if ($tally === null) {
			$migration->method('run')->willThrowException(new RuntimeException('no storage'));
			return $migration;
		}

		$migration->method('run')->willReturn($tally);
		return $migration;
	}//end migration()

	/**
	 * The repair step's output lines, as "kind: text".
	 *
	 * @param array<string, int|bool>|null $tally  The tally, or null to throw.
	 * @param LoggerInterface|null         $logger The logger.
	 *
	 * @return list<string>
	 */
	private function repairLines(?array $tally, ?LoggerInterface $logger=null): array {
		$lines = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message) use (&$lines): void {
			$lines[] = 'info: ' . $message;
		});
		$output->method('warning')->willReturnCallback(function (string $message) use (&$lines): void {
			$lines[] = 'warning: ' . $message;
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectFileMigration::class)->willReturn($this->migration(tally: $tally));

		(new MoveObjectFilesToOpenRegisterAccount($container, $logger ?? $this->createMock(LoggerInterface::class)))->run($output);

		return $lines;
	}//end repairLines()

	/**
	 * The repair step reports the counts, and the refusal first when the storage refused.
	 *
	 * @return void
	 */
	public function testRepairReportsTheTally(): void {
		$counts = 'Object files: 2 folders moved, 5 files moved, 1 published links re-owned, 3 left where they are';

		$this->assertSame(['info: ' . $counts], $this->repairLines(tally: self::tally(refused: false)));
		$this->assertSame(
			['warning: ' . ObjectFileMigration::REFUSAL_MESSAGE, 'info: ' . $counts],
			$this->repairLines(tally: self::tally(refused: true))
		);
	}//end testRepairReportsTheTally()

	/**
	 * A failing move is skipped: logged as a warning and reported, with nothing else.
	 *
	 * @return void
	 */
	public function testRepairSkipsAFailingMove(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->willReturnCallback(
			function (string $message, array $context): void {
				$this->assertSame('[MoveObjectFilesToOpenRegisterAccount] Skipped: no storage', $message);
				$this->assertSame(['file', 'line'], array_keys($context));
			}
		);

		$this->assertSame(['warning: Object files: not moved (no storage)'], $this->repairLines(tally: null, logger: $logger));
	}//end testRepairSkipsAFailingMove()

	/**
	 * The command prints the counts, the refusal first, and exits 1 on a refusal.
	 *
	 * @return void
	 */
	public function testCommandReportsTheTally(): void {
		$counts = '2 folders moved, 5 files moved, 1 published links re-owned, 3 left where they are';
		foreach ([false => 0, true => 1] as $refused => $exit) {
			$output = new BufferedOutput();
			$command = new MoveObjectFilesCommand($this->migration(tally: self::tally(refused: (bool) $refused)));

			$this->assertSame($exit, $command->run(new ArrayInput([]), $output));
			$expected = $counts . "\n";
			if ($refused === 1) {
				$expected = ObjectFileMigration::REFUSAL_MESSAGE . "\n" . $expected;
			}

			$this->assertSame($expected, $output->fetch());
		}
	}//end testCommandReportsTheTally()
}//end class
