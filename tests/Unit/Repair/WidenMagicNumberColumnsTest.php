<?php

/**
 * The upgrade step widens every magic table's number columns, reports each
 * table it widened, and continues past a table it cannot alter.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Repair\WidenMagicNumberColumns;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/specs/magic-table-columns/spec.md
 */
class WidenMagicNumberColumnsTest extends TestCase {

	public function testEachTableIsWidenedAndAFailureDoesNotStopTheSweep(): void {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('listMagicTableNames')->willReturn(['openregister_table_a', 'openregister_table_b', 'openregister_table_c']);
		$mapper->expects($this->exactly(3))->method('widenNumberColumnsToDefault')->willReturnCallback(
			static function (string $tableName): array {
				if ($tableName === 'openregister_table_b') {
					throw new RuntimeException('lock timeout');
				}

				return $tableName === 'openregister_table_a' ? ['weight'] : [];
			}
		);

		$infos = [];
		$warnings = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $message) use (&$infos): void {
			$infos[] = $message;
		});
		$output->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});

		(new WidenMagicNumberColumns($mapper, $this->createMock(LoggerInterface::class)))->run($output);

		$this->assertSame('openregister_table_a: widened weight', $infos[0]);
		$this->assertStringContainsString('3 object table(s)', $infos[1]);
		$this->assertStringContainsString('1 widened, 1 could not be', $infos[1]);
		$this->assertCount(1, $warnings);
		$this->assertStringStartsWith('openregister_table_b:', $warnings[0]);
	}//end testEachTableIsWidenedAndAFailureDoesNotStopTheSweep()
}//end class
