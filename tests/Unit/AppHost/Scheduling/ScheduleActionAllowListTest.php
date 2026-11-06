<?php

/**
 * AppHost scheduling — action allow-list tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleActionAllowList;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleDescriptor;
use PHPUnit\Framework\TestCase;

/**
 * Covers the closed action to flow node map.
 *
 * @covers \OCA\OpenRegister\AppHost\Scheduling\ScheduleActionAllowList
 * @uses   \OCA\OpenRegister\AppHost\Scheduling\ScheduleDescriptor
 */
class ScheduleActionAllowListTest extends TestCase {
	private ScheduleActionAllowList $allowList;

	protected function setUp(): void {
		$this->allowList = new ScheduleActionAllowList();
	}

	/**
	 * A schedule with this action and these arguments.
	 *
	 * @param string $action The action type.
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return ScheduleDescriptor The schedule.
	 */
	private function scheduleOf(string $action, array $arguments): ScheduleDescriptor {
		return new ScheduleDescriptor('s', null, '0 6 * * *', $action, $arguments);
	}

	public function testSynchronizationCompilesToItsFlowNodeFromWhatBuildiqEmits(): void {
		$this->assertSame(
			['type' => 'openconnector.synchronization-run', 'config' => ['synchronization' => 'sync-1']],
			$this->allowList->flowNode(
				$this->scheduleOf('openconnector:synchronization', ['synchronizationId' => 'sync-1'])
			)
		);
	}

	public function testAnActionOffTheMapHasNoNode(): void {
		$this->assertNull(
			$this->allowList->flowNode($this->scheduleOf('OCA\\Evil\\Backdoor', ['synchronizationId' => 'x']))
		);
	}

	/**
	 * @dataProvider missingArguments
	 */
	public function testAMissingArgumentHasNoNode(array $arguments): void {
		$this->assertNull(
			$this->allowList->flowNode($this->scheduleOf('openconnector:synchronization', $arguments))
		);
	}

	/**
	 * Argument bags that name no synchronization.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function missingArguments(): array {
		return [
			'absent' => [[]],
			'blank' => [['synchronizationId' => '  ']],
			'not a string' => [['synchronizationId' => ['x']]],
			'the old name' => [['synchronization' => 'sync-1']],
		];
	}
}
