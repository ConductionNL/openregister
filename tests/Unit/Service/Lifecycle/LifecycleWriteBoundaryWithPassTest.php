<?php

/**
 * Unit tests for the lifecycle write boundary with an automatic-transition pass wired.
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
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionRunner;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\LifecycleWriteBoundary;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The write runs inside the pass's boundary, and with no automatic move applied the write's own result comes back.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleWriteBoundary
 */
class LifecycleWriteBoundaryWithPassTest extends TestCase {

	/**
	 * Inside the write the boundary is open; after it, closed; the saved entity is returned.
	 *
	 * @return void
	 */
	public function testTheWriteRunsInsideThePassBoundary(): void {
		$pass     = new AutoTransitionPass($this->createMock(AutoTransitionRunner::class), $this->createMock(LoggerInterface::class));
		$boundary = new LifecycleWriteBoundary(new LifecycleActionContext(), $pass);
		$saved    = new ObjectEntity();
		$saved->setUuid('u-1');
		$inside = null;

		$result = $boundary->around(
			static function () use ($pass, $saved, &$inside): ObjectEntity {
				$inside = $pass->isInsideBoundary();
				return $saved;
			}
		);

		$this->assertTrue($inside);
		$this->assertFalse($pass->isInsideBoundary());
		$this->assertSame('u-1', $result->getUuid());

	}//end testTheWriteRunsInsideThePassBoundary()
}//end class
