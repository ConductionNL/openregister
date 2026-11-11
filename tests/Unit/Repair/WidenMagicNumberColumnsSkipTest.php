<?php

/**
 * WidenMagicNumberColumns skips the sweep when the tables cannot be listed.
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
use OCA\OpenRegister\Repair\WidenMagicNumberColumns;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The name and the listing-failure branch the existing sweep test leaves.
 */
class WidenMagicNumberColumnsSkipTest extends TestCase {

	public function testTheStepNameIsWhatOccPrints(): void {
		$step = new WidenMagicNumberColumns($this->createMock(MagicMapperInterface::class), $this->createMock(LoggerInterface::class));

		$this->assertSame('Widen the number columns of existing object tables so they keep their decimals', $step->getName());
	}

	public function testATableListThatCannotBeReadSkipsWithoutWideningOrReporting(): void {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('listMagicTableNames')->willThrowException(new RuntimeException('no catalog'));
		$mapper->expects($this->never())->method('widenNumberColumnsToDefault');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with('[WidenMagicNumberColumns] Could not list magic tables, skipping: no catalog');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		(new WidenMagicNumberColumns($mapper, $logger))->run($output);
	}
}
