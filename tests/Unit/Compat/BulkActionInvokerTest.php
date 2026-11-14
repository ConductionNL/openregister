<?php

/**
 * Bulk actions in the released positional form and in the BulkStep form both load and run.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Compat
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Compat;

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionInvoker;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\BulkAction\LegacyBulkActionInterface;
use OCA\OpenRegister\BulkAction\StepBulkActionInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Tests\Unit\Compat\Fixtures\ReleasedFormBulkAction;
use OCA\OpenRegister\Tests\Unit\Compat\Fixtures\StepFormBulkAction;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BulkActionInvokerTest extends TestCase {

	private ObjectEntity $object;

	private IUser $actor;

	protected function setUp(): void {
		$this->object = new ObjectEntity();
		$this->actor  = $this->createMock(IUser::class);
	}

	private function step(): BulkStep {
		return new BulkStep(object: $this->object, parameters: ['k' => 'v'], commit: true, actor: $this->actor);
	}

	public function testReleasedFormActionReceivesThePositionalArguments(): void {
		$action = new ReleasedFormBulkAction();

		$result = (new BulkActionInvoker())->apply(action: $action, step: $this->step());

		$this->assertSame([$this->object, ['k' => 'v'], true, $this->actor], $action->received);
		$this->assertSame('released', $result->getReason());
	}

	public function testStepFormActionReceivesTheStep(): void {
		$action = new StepFormBulkAction();
		$step   = $this->step();

		$result = (new BulkActionInvoker())->apply(action: $action, step: $step);

		$this->assertSame($step, $action->received);
		$this->assertSame('step', $result->getReason());
	}

	public function testExplicitStepInterfaceIsCalledWithTheStep(): void {
		$action = $this->createMock(StepBulkActionInterface::class);
		$step   = $this->step();
		$action->expects($this->once())->method('apply')->with($step)->willReturn(BulkActionResult::skipped(reason: 's'));

		$this->assertSame('s', (new BulkActionInvoker())->apply(action: $action, step: $step)->getReason());
	}

	public function testExplicitLegacyInterfaceIsCalledPositionally(): void {
		$action = $this->createMock(LegacyBulkActionInterface::class);
		$action->expects($this->once())->method('apply')->with($this->object, ['k' => 'v'], true, $this->actor)->willReturn(BulkActionResult::skipped(reason: 'l'));

		$this->assertSame('l', (new BulkActionInvoker())->apply(action: $action, step: $this->step())->getReason());
	}

	public function testActionWithoutApplyIsRefusedLoudly(): void {
		$this->expectException(RuntimeException::class);

		(new BulkActionInvoker())->apply(action: $this->createMock(BulkActionInterface::class), step: $this->step());
	}
}
