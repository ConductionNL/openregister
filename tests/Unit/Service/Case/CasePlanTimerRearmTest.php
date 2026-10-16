<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Service\Case\CasePlanItemTimers;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Case\CasePlanTimerRearm;
use OCA\OpenRegister\Service\Case\UnarmedTimerMarkers;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The re-arm pass over plan items whose statutory expiry timer could not be armed.
 *
 * @covers \OCA\OpenRegister\Service\Case\CasePlanTimerRearm
 * @covers \OCA\OpenRegister\Service\Case\UnarmedTimerMarkers
 * @covers \OCA\OpenRegister\Service\Case\CasePlanItemTimers
 */
class CasePlanTimerRearmTest extends TestCase {

	private FakeCaseItemMapper $items;

	private FlowTimerService&MockObject $core;

	private FlowTimerMapper&MockObject $timerRows;

	private CasePlanService&MockObject $plans;

	private UnarmedTimerMarkers $markers;

	private string $stored = '';

	/**
	 * Fresh tables, an in-memory marker store and a clock core.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->items = new FakeCaseItemMapper($this);
		$this->core = $this->createMock(FlowTimerService::class);
		$this->timerRows = $this->createMock(FlowTimerMapper::class);
		$this->plans = $this->createMock(CasePlanService::class);
		$this->stored = '';
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (): string => $this->stored);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored = $value;

				return true;
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (): void {
				$this->stored = '';
			}
		);
		$this->markers = new UnarmedTimerMarkers(appConfig: $appConfig);
	}//end setUp()

	/**
	 * The pass under test.
	 *
	 * @return CasePlanTimerRearm The pass.
	 */
	private function pass(): CasePlanTimerRearm {
		return new CasePlanTimerRearm(
			markers: $this->markers,
			items: $this->items,
			timerRows: $this->timerRows,
			timers: new CasePlanItemTimers(timers: $this->core, logger: new NullLogger(), markers: $this->markers),
			plans: $this->plans,
			logger: new NullLogger()
		);
	}//end pass()

	/**
	 * A statutory item that is active and marked.
	 *
	 * @param string $state The item's state.
	 * @param string $expires The expiry moment, relative to the pass's clock.
	 *
	 * @return CaseItem The marked row.
	 */
	private function markedItem(string $state = CaseItem::STATE_ACTIVE, string $expires = '+10 days'): CaseItem {
		$row = CaseFixtures::row(id: 1, key: 'beoordeling', type: CaseItem::TYPE_HUMAN_TASK, state: $state);
		$row->setExpiresAt(new DateTime($expires));
		$row->setPlanSettings(['statutory' => ['beoordeling']]);
		$this->items->seed([$row]);
		$this->markers->add(itemUuid: (string)$row->getUuid());

		return $row;
	}//end markedItem()

	/**
	 * A marked active item without a timer is armed with the enforcing outcome, and its marker goes.
	 *
	 * @return void
	 */
	public function testAMarkedActiveItemGetsItsEnforcingTimerAndLosesItsMarker(): void {
		$row = $this->markedItem();
		$this->timerRows->method('findBySubject')->willReturn([]);
		$configs = [];
		$this->core->method('arm')->willReturnCallback(
			static function (array $config) use (&$configs): FlowTimer {
				$configs[] = $config;

				return new FlowTimer();
			}
		);

		$result = $this->pass()->run(now: new DateTimeImmutable());

		$this->assertSame(['armed' => 1, 'enforced' => 0, 'dropped' => 0, 'failed' => 0], $result);
		$this->assertCount(1, $configs);
		$this->assertSame('expiry', $configs[0]['purpose']);
		$this->assertSame('transition:terminate', $configs[0]['onExpiry']);
		$this->assertSame((string)$row->getUuid(), $configs[0]['subjectUuid']);
		$this->assertSame([], $this->markers->all());
	}//end testAMarkedActiveItemGetsItsEnforcingTimerAndLosesItsMarker()

	/**
	 * An arm that is refused again keeps the marker for the next pass.
	 *
	 * @return void
	 */
	public function testARefusedArmKeepsTheMarker(): void {
		$row = $this->markedItem();
		$this->timerRows->method('findBySubject')->willReturn([]);
		$this->core->method('arm')->willThrowException(new RuntimeException('calendar unknown'));

		$result = $this->pass()->run(now: new DateTimeImmutable());

		$this->assertSame(['armed' => 0, 'enforced' => 0, 'dropped' => 0, 'failed' => 1], $result);
		$this->assertSame([(string)$row->getUuid()], array_keys($this->markers->all()));
	}//end testARefusedArmKeepsTheMarker()

	/**
	 * A term that passed while nothing watched it is enforced as the fired timer would have: the item is terminated.
	 *
	 * @return void
	 */
	public function testATermThatPassedMeanwhileTerminatesTheItem(): void {
		$row = $this->markedItem(expires: '-1 hour');
		$this->timerRows->method('findBySubject')->willReturn([]);
		$this->core->expects($this->never())->method('arm');
		$this->plans->expects($this->once())->method('onTimerExpired')->with((string)$row->getUuid(), CasePlanTimerRearm::CAUSE_REF)->willReturn(true);

		$result = $this->pass()->run(now: new DateTimeImmutable());

		$this->assertSame(['armed' => 0, 'enforced' => 1, 'dropped' => 0, 'failed' => 0], $result);
		$this->assertSame([], $this->markers->all());
	}//end testATermThatPassedMeanwhileTerminatesTheItem()

	/**
	 * Nothing is armed for an item that is gone, no longer active, or already has a running expiry timer.
	 *
	 * @return void
	 */
	public function testAnItemThatNeedsNothingLosesItsMarkerWithoutArming(): void {
		$this->core->expects($this->never())->method('arm');
		$this->plans->expects($this->never())->method('onTimerExpired');
		$this->markers->add(itemUuid: 'gone');
		$done = $this->markedItem(state: CaseItem::STATE_COMPLETED);
		$this->timerRows->method('findBySubject')->willReturn([]);

		$result = $this->pass()->run(now: new DateTimeImmutable());
		$this->assertSame(['armed' => 0, 'enforced' => 0, 'dropped' => 2, 'failed' => 0], $result);
		$this->assertSame([], $this->markers->all());

		// An active item whose timer was armed after all.
		$this->setUp();
		$this->core->expects($this->never())->method('arm');
		$this->markedItem();
		$running = new FlowTimer();
		$running->setPurpose(FlowTimer::PURPOSE_EXPIRY);
		$this->timerRows->method('findBySubject')->willReturn([$running]);
		$this->assertSame(1, $this->pass()->run(now: new DateTimeImmutable())['dropped']);
		$this->assertSame([], $this->markers->all());
		$this->assertSame(CaseItem::STATE_COMPLETED, $done->getState());
	}//end testAnItemThatNeedsNothingLosesItsMarkerWithoutArming()

	/**
	 * The marker keeps the first moment, and unreadable stored values never break a read.
	 *
	 * @return void
	 */
	public function testTheMarkerStoreKeepsTheFirstMomentAndToleratesGarbage(): void {
		$this->markers->add(itemUuid: 'item-a', now: new DateTimeImmutable('2026-10-01T10:00:00+00:00'));
		$this->markers->add(itemUuid: 'item-a', now: new DateTimeImmutable('2026-10-02T10:00:00+00:00'));
		$this->assertSame(['item-a' => '2026-10-01T10:00:00+00:00'], $this->markers->all());

		$this->stored = 'not json';
		$this->assertSame([], $this->markers->all());
		$this->stored = '{"item-b":"2026-10-01T10:00:00+00:00","":"x","item-c":5}';
		$this->assertSame(['item-b' => '2026-10-01T10:00:00+00:00'], $this->markers->all());
	}//end testTheMarkerStoreKeepsTheFirstMomentAndToleratesGarbage()
}//end class
