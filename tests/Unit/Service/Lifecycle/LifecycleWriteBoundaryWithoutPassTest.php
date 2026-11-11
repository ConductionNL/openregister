<?php

/**
 * Unit tests for the lifecycle write boundary when no automatic-transition pass is wired.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\LifecycleWriteBoundary;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Without a pass the boundary returns the write's own result and applies no action;
 * the system mark is released even when the write throws.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleWriteBoundary
 */
class LifecycleWriteBoundaryWithoutPassTest extends TestCase {

	/**
	 * The write's own result comes back, and no action is being applied.
	 *
	 * @return void
	 */
	public function testTheWritesOwnResultComesBack(): void {
		$saved    = new ObjectEntity();
		$boundary = new LifecycleWriteBoundary(new LifecycleActionContext());

		$this->assertSame($saved, $boundary->around(static fn (): ObjectEntity => $saved));
		$this->assertNull($boundary->applyingAction());

	}//end testTheWritesOwnResultComesBack()

	/**
	 * A refused system write still releases the system mark.
	 *
	 * @return void
	 */
	public function testARefusedSystemWriteReleasesTheMark(): void {
		$context  = new LifecycleActionContext();
		$boundary = new LifecycleWriteBoundary($context);

		try {
			$boundary->runningAsSystem(
				'u-1',
				'procest',
				static function () use ($context): never {
					throw new RuntimeException('refused while ' . $context->systemAppFor('u-1'));
				}
			);
			$this->fail('the refusal did not reach the caller');
		} catch (RuntimeException $e) {
			$this->assertSame('refused while procest', $e->getMessage());
		}

		$this->assertNull($context->systemAppFor('u-1'));

	}//end testARefusedSystemWriteReleasesTheMark()
}//end class
