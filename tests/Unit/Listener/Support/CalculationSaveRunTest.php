<?php

/**
 * CalculationSaveRun marks itself changed only when a write alters the payload.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unit-test-coverage/specs/unit-test-coverage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Listener\Support\CalculationSaveRun;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Listener\Support\CalculationSaveRun
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class CalculationSaveRunTest extends TestCase {

	private static function saveRun(array $payload): CalculationSaveRun {
		return new CalculationSaveRun(object: new ObjectEntity(), slug: 'invoice', sequenceContext: null, payload: $payload);
	}//end saveRun()

	public function testWritingTheValueAlreadyThereChangesNothing(): void {
		$run = self::saveRun(['total' => 100, 'note' => null]);

		$run->write(name: 'total', value: 100);
		$run->write(name: 'note', value: null);

		self::assertFalse($run->changed());
		self::assertSame(['total' => 100, 'note' => null], $run->payload());
	}//end testWritingTheValueAlreadyThereChangesNothing()

	public function testANewOrDifferentValueIsWrittenAndMarksTheRunChanged(): void {
		$run = self::saveRun(['total' => 100]);

		$run->write(name: 'total', value: 120);
		$run->write(name: 'vat', value: 25.2);

		self::assertTrue($run->changed());
		self::assertSame(['total' => 120, 'vat' => 25.2], $run->payload());
	}//end testANewOrDifferentValueIsWrittenAndMarksTheRunChanged()

	public function testTheComparisonIsStrictSoATypeChangeCounts(): void {
		$run = self::saveRun(['total' => 100]);

		$run->write(name: 'total', value: '100');

		self::assertTrue($run->changed());
		self::assertSame(['total' => '100'], $run->payload());
	}//end testTheComparisonIsStrictSoATypeChangeCounts()
}//end class
