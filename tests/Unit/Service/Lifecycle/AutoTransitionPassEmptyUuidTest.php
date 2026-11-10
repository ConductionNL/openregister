<?php

/**
 * Unit tests for recording a write without a uuid in an automatic-transition pass.
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

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A write without a uuid is not recorded, so the pass never asks the runner about it.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass
 */
class AutoTransitionPassEmptyUuidTest extends TestCase {

	/**
	 * Only the write with a uuid reaches the runner when the boundary closes.
	 *
	 * @return void
	 */
	public function testAWriteWithoutUuidIsNotRecorded(): void {
		$asked  = [];
		$runner = $this->createMock(AutoTransitionRunner::class);
		$runner->method('decide')->willReturnCallback(
			static function (ObjectRef $ref) use (&$asked) {
				$asked[] = $ref->id;
				return null;
			}
		);

		$pass = new AutoTransitionPass($runner, $this->createMock(LoggerInterface::class));
		$pass->enter();
		$pass->record(new ObjectRef('crm', 'lead', ''), ['status' => 'new']);
		$pass->record(new ObjectRef('crm', 'lead', 'u-1'), ['status' => 'new']);

		$this->assertSame([], $pass->leave());
		$this->assertSame(['u-1'], $asked);

	}//end testAWriteWithoutUuidIsNotRecorded()
}//end class
