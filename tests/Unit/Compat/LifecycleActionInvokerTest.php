<?php

/**
 * Lifecycle actions in the released positional form and in the context form both load and run.
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

use OCA\OpenRegister\Lifecycle\ContextLifecycleActionInterface;
use OCA\OpenRegister\Lifecycle\LegacyLifecycleActionInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionInvoker;
use OCA\OpenRegister\Tests\Unit\Compat\Fixtures\ContextFormLifecycleAction;
use OCA\OpenRegister\Tests\Unit\Compat\Fixtures\ReleasedFormLifecycleAction;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LifecycleActionInvokerTest extends TestCase {

	private function context(): LifecycleActionContext {
		return new LifecycleActionContext(
			objectData: ['id' => 'a'],
			previousData: ['id' => 'b'],
			parameters: ['p' => 1],
			actionName: 'stamp'
		);
	}

	public function testReleasedFormActionReceivesThePositionalArguments(): void {
		$action = new ReleasedFormLifecycleAction();

		$result = (new LifecycleActionInvoker())->execute(action: $action, context: $this->context());

		$this->assertSame([['id' => 'a'], ['id' => 'b'], ['p' => 1], 'stamp'], $action->received);
		$this->assertSame(['id' => 'a', 'form' => 'released'], $result);
	}

	public function testContextFormActionReceivesTheContext(): void {
		$action  = new ContextFormLifecycleAction();
		$context = $this->context();

		$result = (new LifecycleActionInvoker())->execute(action: $action, context: $context);

		$this->assertSame($context, $action->received);
		$this->assertSame(['id' => 'a', 'form' => 'context'], $result);
	}

	public function testExplicitContextInterfaceIsCalledWithTheContext(): void {
		$action = $this->createMock(ContextLifecycleActionInterface::class);
		$context = $this->context();
		$action->expects($this->once())->method('execute')->with($context)->willReturn(['ok' => true]);

		$this->assertSame(['ok' => true], (new LifecycleActionInvoker())->execute(action: $action, context: $context));
	}

	public function testExplicitLegacyInterfaceIsCalledPositionally(): void {
		$action = $this->createMock(LegacyLifecycleActionInterface::class);
		$action->expects($this->once())->method('execute')->with(['id' => 'a'], ['id' => 'b'], ['p' => 1], 'stamp')->willReturn(['ok' => true]);

		$this->assertSame(['ok' => true], (new LifecycleActionInvoker())->execute(action: $action, context: $this->context()));
	}

	public function testActionWithoutExecuteIsRefusedLoudly(): void {
		$this->expectException(RuntimeException::class);

		(new LifecycleActionInvoker())->execute(action: $this->createMock(LifecycleActionInterface::class), context: $this->context());
	}
}
