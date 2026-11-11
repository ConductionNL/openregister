<?php

/**
 * Characterisation of the chunk log lines of a parallel mass validation run.
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
 * A parallel run logs the start and the end of each chunk of up to four batches.
 *
 * @covers \OCA\OpenRegister\Service\MassValidationRun
 */
class MassValidationRunParallelLogTest extends TestCase {

	/**
	 * Six single-object batches make two chunks, of four and of two batches.
	 *
	 * @return void
	 */
	public function testEachChunkLogsItsStartAndEnd(): void {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('getAllRegisterSchemaPairs')->willReturn([['registerId' => 1, 'schemaId' => 2]]);
		$mapper->method('countSearchObjects')->willReturn(6);
		$mapper->method('searchObjects')->willReturn([]);

		$starts = [];
		$ends   = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context) use (&$starts, &$ends): void {
				unset($context['file'], $context['line']);
				if ($message === '[SettingsService] 🔄 PROCESSING PARALLEL CHUNK') {
					$starts[] = $context;
				}

				if ($message === '[SettingsService] ✅ COMPLETED PARALLEL CHUNK') {
					unset($context['chunkTime']);
					$ends[] = $context;
				}
			}
		);

		$run    = new MassValidationRun($mapper, $this->createMock(AppSettingsInterface::class), $logger, $this->createMock(ContainerInterface::class));
		$result = $run->run(['mode' => 'parallel', 'max_objects' => 0, 'batch_size' => 1, 'collect_errors' => false]);

		$this->assertSame(
			[
				['chunkIndex' => 1, 'totalChunks' => 2, 'batchesInChunk' => 4],
				['chunkIndex' => 2, 'totalChunks' => 2, 'batchesInChunk' => 2],
			],
			$starts
		);
		$this->assertSame(
			[
				['chunkIndex' => 1, 'objectsProcessed' => 0, 'totalProcessed' => 0],
				['chunkIndex' => 2, 'objectsProcessed' => 0, 'totalProcessed' => 0],
			],
			$ends
		);
		$this->assertSame(6, $result['stats']['batches_processed']);

	}//end testEachChunkLogsItsStartAndEnd()
}//end class
