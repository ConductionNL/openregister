<?php

/**
 * NarrowMagicJsonColumnsTest: the upgrade narrows small tables, reports the rest, and never fails.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\MagicMapper\MagicJsonColumnNarrower;
use OCA\OpenRegister\Repair\NarrowMagicJsonColumns;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Repair\NarrowMagicJsonColumns
 *
 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
 */
class NarrowMagicJsonColumnsTest extends TestCase {

	/**
	 * Output lines, as `level message`.
	 *
	 * @var list<string>
	 */
	private array $lines = [];

	/**
	 * Run the step on a narrower.
	 *
	 * @param MagicJsonColumnNarrower $narrower The narrower.
	 *
	 * @return void
	 */
	private function runStep(MagicJsonColumnNarrower $narrower): void {
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->lines[] = $level . ' ' . $message;
				}
			);
		}

		(new NarrowMagicJsonColumns($narrower, $this->createMock(LoggerInterface::class)))->run($output);
	}//end runStep()

	public function testOffMariaDbTheStepDoesNothing(): void {
		$narrower = $this->createMock(MagicJsonColumnNarrower::class);
		$narrower->method('applies')->willReturn(false);
		$narrower->expects($this->never())->method('narrowPending');

		$this->runStep($narrower);

		$this->assertSame([], $this->lines);
	}//end testOffMariaDbTheStepDoesNothing()

	public function testItNarrowsWithinTheUpgradeLimitsAndSaysNothingWhenNothingWasPending(): void {
		$narrower = $this->createMock(MagicJsonColumnNarrower::class);
		$narrower->method('applies')->willReturn(true);
		$narrower->expects($this->once())->method('narrowPending')
			->with(NarrowMagicJsonColumns::MAX_TABLE_BYTES, NarrowMagicJsonColumns::BUDGET_SECONDS)
			->willReturn(['narrowed' => [], 'failed' => [], 'left' => []]);

		$this->runStep($narrower);

		$this->assertSame([], $this->lines);
	}//end testItNarrowsWithinTheUpgradeLimitsAndSaysNothingWhenNothingWasPending()

	public function testItReportsWhatWasNarrowedWhatFailedAndWhatIsLeftForTheCommand(): void {
		$narrower = $this->createMock(MagicJsonColumnNarrower::class);
		$narrower->method('applies')->willReturn(true);
		$narrower->method('narrowPending')->willReturn(
			[
				'narrowed' => ['oc_openregister_table_1_2', 'oc_openregister_table_3_4'],
				'failed' => ['oc_openregister_table_5_6' => 'Data too long for column'],
				'left' => ['oc_openregister_table_7_8'],
			]
		);

		$this->runStep($narrower);

		$this->assertSame(
			[
				'warning oc_openregister_table_5_6: its JSON columns were left as they are: Data too long for column',
				'info Narrowed the JSON columns of 2 object table(s); 1 could not be.',
				'warning 1 large object table(s) still hold JSON in LONGTEXT columns, which slows every database upgrade.'
					. ' Run "occ openregister:tables:narrow-json" to rebuild them.',
			],
			$this->lines
		);
	}//end testItReportsWhatWasNarrowedWhatFailedAndWhatIsLeftForTheCommand()

	public function testAFailingCatalogueNeverFailsTheUpgrade(): void {
		$narrower = $this->createMock(MagicJsonColumnNarrower::class);
		$narrower->method('applies')->willReturn(true);
		$narrower->method('narrowPending')->willThrowException(new RuntimeException('catalogue unreadable'));

		$this->runStep($narrower);

		$this->assertSame(['warning Could not check the JSON columns of the object tables: catalogue unreadable'], $this->lines);
	}//end testAFailingCatalogueNeverFailsTheUpgrade()
}//end class
