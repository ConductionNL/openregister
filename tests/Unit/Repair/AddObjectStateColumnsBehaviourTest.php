<?php

/**
 * AddObjectStateColumns counts healed and failed tables without ever stopping the upgrade.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Repair\AddObjectStateColumns;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every outcome of one table's heal, in one sweep, with the healer stubbed.
 */
class AddObjectStateColumnsBehaviourTest extends TestCase {

	private MagicMapperInterface&MockObject $magicMapper;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->magicMapper = $this->createMock(MagicMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function step(): AddObjectStateColumns {
		return new AddObjectStateColumns($this->magicMapper, $this->logger);
	}

	public function testTheStepNameIsWhatOccPrints(): void {
		$this->assertSame(
			'Add the archive, freeze and lifecycle status columns to existing object tables',
			$this->step()->getName()
		);
	}

	public function testATableListThatCannotBeReadSkipsTheStep(): void {
		$this->magicMapper->method('listMagicTableNames')->willThrowException(new RuntimeException('no catalog'));
		$this->magicMapper->expects($this->never())->method('healMetadataColumns');
		$this->logger->expects($this->once())->method('info')
			->with('[AddObjectStateColumns] Could not list magic tables, skipping: no catalog');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		$this->step()->run($output);
	}

	public function testHealedUntouchedFailedAndUnreadableTablesAreEachCounted(): void {
		$this->magicMapper->method('listMagicTableNames')->willReturn(['t_healed', 't_clean', 't_failed', 't_unreadable']);
		$this->magicMapper->method('healMetadataColumns')->willReturnCallback(
			static function (string $tableName): array {
				return match ($tableName) {
					't_healed' => ['added' => ['_archived'], 'failed' => []],
					't_clean' => ['added' => [], 'failed' => []],
					't_failed' => ['added' => ['_frozen'], 'failed' => ['_archived' => 'locked']],
					't_unreadable' => throw new RuntimeException('gone'),
				};
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with('[AddObjectStateColumns] Could not read table t_unreadable: gone');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('Checked 4 object table(s) for their metadata columns: 1 healed, 2 could not be.');

		$this->step()->run($output);
	}

	public function testAnInstanceWithoutObjectTablesReportsZero(): void {
		$this->magicMapper->method('listMagicTableNames')->willReturn([]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('Checked 0 object table(s) for their metadata columns: 0 healed, 0 could not be.');

		$this->step()->run($output);
	}
}
