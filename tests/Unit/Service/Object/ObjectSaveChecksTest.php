<?php

/**
 * ObjectSaveChecks runs every save check and collects their violations.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Listener\CodedValueValidationListener;
use OCA\OpenRegister\Listener\DependentValueListener;
use OCA\OpenRegister\Service\Object\ObjectSaveChecks;
use PHPUnit\Framework\TestCase;

/**
 * Both checks run on the same object, schema and stored version; their
 * violations come back in order, coded values first.
 *
 * @covers \OCA\OpenRegister\Service\Object\ObjectSaveChecks
 */
class ObjectSaveChecksTest extends TestCase {

	/**
	 * Every check runs and the violations are concatenated in order.
	 *
	 * @return void
	 */
	public function testViolationsOfEveryCheckAreCollected(): void {
		$schema   = $this->createMock(Schema::class);
		$existing = $this->createMock(ObjectEntity::class);
		$object   = ['status' => 'x'];

		$coded = $this->createMock(CodedValueValidationListener::class);
		$coded->expects($this->once())->method('check')->with($object, $schema, $existing)->willReturn(['status: unknown code']);
		$dependent = $this->createMock(DependentValueListener::class);
		$dependent->expects($this->once())->method('check')->with($object, $schema, $existing)->willReturn(['reason: required when status is x', 'end: before start']);

		$checks = new ObjectSaveChecks($coded, $dependent);

		$this->assertSame([$coded, $dependent], $checks->all());
		$this->assertSame(['status: unknown code', 'reason: required when status is x', 'end: before start'], $checks->violations($object, $schema, $existing));
	}//end testViolationsOfEveryCheckAreCollected()

	/**
	 * Clean checks give no violations, also for a new object.
	 *
	 * @return void
	 */
	public function testCleanChecksGiveNoViolations(): void {
		$coded = $this->createMock(CodedValueValidationListener::class);
		$coded->method('check')->willReturn([]);
		$dependent = $this->createMock(DependentValueListener::class);
		$dependent->method('check')->willReturn([]);

		$this->assertSame([], (new ObjectSaveChecks($coded, $dependent))->violations([], $this->createMock(Schema::class), null));
	}//end testCleanChecksGiveNoViolations()
}//end class
