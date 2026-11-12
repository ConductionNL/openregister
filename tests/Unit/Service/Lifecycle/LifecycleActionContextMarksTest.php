<?php

/**
 * Unit tests for the nested system and declared-state marks of the lifecycle action context.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use PHPUnit\Framework\TestCase;

/**
 * Marks nest per object; leaving an object that holds no mark is a no-op and touches no other object.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext
 */
class LifecycleActionContextMarksTest extends TestCase {

	/**
	 * System marks nest, the innermost app answers, and an unmarked leave changes nothing.
	 *
	 * @return void
	 */
	public function testSystemMarksNestAndAnUnmarkedLeaveIsANoOp(): void {
		$context = new LifecycleActionContext();
		$context->enterSystem('u-1', 'opencatalogi');
		$context->enterSystem('u-1', 'procest');

		$context->leaveSystem('u-2');
		$this->assertSame('procest', $context->systemAppFor('u-1'));
		$this->assertNull($context->systemAppFor('u-2'));

		$context->leaveSystem('u-1');
		$this->assertSame('opencatalogi', $context->systemAppFor('u-1'));
		$context->leaveSystem('u-1');
		$this->assertNull($context->systemAppFor('u-1'));
		$context->leaveSystem('u-1');
		$this->assertNull($context->systemAppFor('u-1'));

	}//end testSystemMarksNestAndAnUnmarkedLeaveIsANoOp()

	/**
	 * Declared-state marks count per object; an unmarked leave changes nothing.
	 *
	 * @return void
	 */
	public function testDeclaredStateMarksCountAndAnUnmarkedLeaveIsANoOp(): void {
		$context = new LifecycleActionContext();
		$context->enterDeclaredState('u-1');
		$context->enterDeclaredState('u-1');

		$context->leaveDeclaredState('u-2');
		$this->assertTrue($context->writesDeclaredState('u-1'));
		$this->assertFalse($context->writesDeclaredState('u-2'));

		$context->leaveDeclaredState('u-1');
		$this->assertTrue($context->writesDeclaredState('u-1'), 'one mark is still held');
		$context->leaveDeclaredState('u-1');
		$this->assertFalse($context->writesDeclaredState('u-1'));

	}//end testDeclaredStateMarksCountAndAnUnmarkedLeaveIsANoOp()
}//end class
