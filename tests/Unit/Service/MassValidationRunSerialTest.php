<?php

/**
 * Unit tests for the serial mode of a mass validation run: progress per batch and the periodic memory cleanup.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Service\MassValidationRun;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every batch logs its progress; every tenth batch also cleans up memory.
 *
 * @covers \OCA\OpenRegister\Service\MassValidationRun
 */
class MassValidationRunSerialTest extends TestCase {

	/**
	 * Run serially over the given number of single-object batches and collect the log lines.
	 *
	 * @param int $batches The number of batches.
	 *
	 * @return array{0: list<string>, 1: list<array<string, mixed>>, 2: array<string, mixed>} Debug messages, progress contexts and the result.
	 */
	private function runSerial(int $batches): array {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('countSearchObjects')->willReturn($batches);
		$mapper->method('findAllListed')->willReturn([]);

		$debug    = [];
		$progress = [];
		$logger   = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			static function (string $message) use (&$debug): void {
				$debug[] = $message;
			}
		);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context) use (&$progress): void {
				if ($message === '[SettingsService] 📈 MASS VALIDATION PROGRESS') {
					$progress[] = $context;
				}
			}
		);

		$run    = new MassValidationRun($mapper, $this->createMock(AppSettingsInterface::class), $logger, $this->createMock(ContainerInterface::class));
		$result = $run->run(['mode' => 'serial', 'max_objects' => 0, 'batch_size' => 1, 'collect_errors' => false]);

		return [$debug, $progress, $result];

	}//end runSerial()

	/**
	 * Ten batches log ten progress lines and clean up memory once, after the tenth.
	 *
	 * @return void
	 */
	public function testTheTenthBatchCleansUpMemory(): void {
		[$debug, $progress, $result] = $this->runSerial(10);

		$this->assertSame(['[SettingsService] 🧹 MEMORY CLEANUP'], array_values(array_filter($debug, static fn (string $m): bool => str_contains($m, 'MEMORY CLEANUP'))));
		$this->assertSame(range(1, 10), array_column($progress, 'batchNumber'));
		$this->assertSame([10], array_unique(array_column($progress, 'totalBatches')));
		$this->assertSame(10, $result['stats']['batches_processed']);

	}//end testTheTenthBatchCleansUpMemory()

	/**
	 * Nine batches never reach the cleanup.
	 *
	 * @return void
	 */
	public function testNineBatchesDoNotCleanUp(): void {
		[$debug, $progress] = $this->runSerial(9);

		$this->assertSame([], array_values(array_filter($debug, static fn (string $m): bool => str_contains($m, 'MEMORY CLEANUP'))));
		$this->assertCount(9, $progress);

	}//end testNineBatchesDoNotCleanUp()
}//end class
