<?php

/**
 * Unit tests for validating the end-date roll of an SLA declaration.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/end-date-roll-on-the-calendar/specs/flow-business-timers/spec.md#requirement-a-budget-may-roll-its-end-date-to-a-working-day
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use OCA\OpenRegister\Service\Flow\Timer\SlaDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * No roll means none; a declared roll must be one of the known ones.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Timer\SlaDeclaration
 */
class SlaDeclarationRollTest extends TestCase {

	/**
	 * An absent or empty roll is no roll; a known roll is kept; an unknown one is refused.
	 *
	 * @return void
	 */
	public function testTheRollIsValidated(): void {
		$declaration = new SlaDeclaration();

		$this->assertSame(SlaCalculatorInterface::ROLL_NONE, $declaration->validateRoll(null));
		$this->assertSame(SlaCalculatorInterface::ROLL_NONE, $declaration->validateRoll(''));
		$this->assertSame(SlaCalculatorInterface::ROLL_NEXT, $declaration->validateRoll(SlaCalculatorInterface::ROLL_NEXT));

		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("rollToWorkingDay ''sideways'' is refused: use one of none, next, previous.");
		$declaration->validateRoll('sideways');

	}//end testTheRollIsValidated()
}//end class
