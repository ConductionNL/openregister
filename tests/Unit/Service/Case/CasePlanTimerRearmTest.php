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
 * @uses \OCA\OpenRegister\Db\CaseItem
 * @uses \OCA\OpenRegister\Db\CaseItemMapper
 * @uses \OCA\OpenRegister\Db\FlowTimer
 */
class CasePlanTimerRearmTest extends TestCase {

	private FakeCaseItemMapper $items;

	private FlowTimerService&MockObject $core;

	private FlowTimerMapper&MockObject $timerRows;

	private CasePlanService&MockObject $plans;

	private UnarmedTimerMarkers $markers;

	/**
	 * @var array<string, string>
	 */
	private array $stored = [];

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
		$this->stored = [];
		$appConfig = CaseFixtures::memoryAppConfig(case: $this, store: $this->stored);
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
	 * An enforcement whose transition threw is reported failed and keeps its marker for the next pass.
	 *
	 * @return void
	 */
	public function testAnEnforcementThatThrewIsFailedAndKeepsTheMarker(): void {
		$row = $this->markedItem(expires: '-1 hour');
		$this->timerRows->method('findBySubject')->willReturn([]);
		$this->plans->method('onTimerExpired')->willReturn(false);

		$result = $this->pass()->run(now: new DateTimeImmutable());

		$this->assertSame(['armed' => 0, 'enforced' => 0, 'dropped' => 0, 'failed' => 1], $result);
		$this->assertSame([(string)$row->getUuid()], array_keys($this->markers->all()));
	}//end testAnEnforcementThatThrewIsFailedAndKeepsTheMarker()

	/**
	 * An enforcement refused because the item went terminal meanwhile needs nothing more: the marker goes.
	 *
	 * @return void
	 */
	public function testAnEnforcementRefusedForATerminalItemDropsTheMarker(): void {
		$row = $this->markedItem(expires: '-1 hour');
		$this->timerRows->method('findBySubject')->willReturn([]);
		$this->plans->method('onTimerExpired')->willReturnCallback(
			static function () use ($row): bool {
				$row->setState(CaseItem::STATE_COMPLETED);

				return false;
			}
		);

		$result = $this->pass()->run(now: new DateTimeImmutable());

		$this->assertSame(['armed' => 0, 'enforced' => 0, 'dropped' => 1, 'failed' => 0], $result);
		$this->assertSame([], $this->markers->all());
	}//end testAnEnforcementRefusedForATerminalItemDropsTheMarker()

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

		// Another key of the app, an empty marker and a bare prefix are not markers.
		$this->stored['something_else'] = 'x';
		$this->stored[UnarmedTimerMarkers::KEY_PREFIX . 'item-e'] = '';
		$this->stored[UnarmedTimerMarkers::KEY_PREFIX] = 'y';
		$this->assertSame(['item-a' => '2026-10-01T10:00:00+00:00'], $this->markers->all());
	}//end testTheMarkerStoreKeepsTheFirstMomentAndToleratesGarbage()

	/**
	 * Every item has its own key, so clearing one marker never rewrites another and an unusable uuid is refused.
	 *
	 * @return void
	 */
	public function testEveryItemHasItsOwnKeyAndAnOverlongUuidIsRefused(): void {
		$this->markers->add(itemUuid: 'item-a', now: new DateTimeImmutable('2026-10-01T10:00:00+00:00'));
		$this->markers->add(itemUuid: 'item-b', now: new DateTimeImmutable('2026-10-02T10:00:00+00:00'));
		$this->assertSame(
			[UnarmedTimerMarkers::KEY_PREFIX . 'item-a', UnarmedTimerMarkers::KEY_PREFIX . 'item-b'],
			array_keys($this->stored)
		);

		$this->markers->remove(itemUuid: 'item-a');
		$this->assertSame(['item-b' => '2026-10-02T10:00:00+00:00'], $this->markers->all());
		$this->markers->remove(itemUuid: '');

		$this->assertFalse($this->markers->add(itemUuid: ''));
		$this->assertFalse($this->markers->add(itemUuid: str_repeat('x', 60)));
		$this->assertSame(['item-b'], array_keys($this->markers->all()));
	}//end testEveryItemHasItsOwnKeyAndAnOverlongUuidIsRefused()

	/**
	 * An app config that throws never breaks the pass: nothing is marked, listed or cleared.
	 *
	 * @return void
	 */
	public function testAnUnreadableAppConfigIsToleratedEverywhere(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getKeys')->willThrowException(new RuntimeException('db gone'));
		$config->method('getValueString')->willThrowException(new RuntimeException('db gone'));
		$config->method('deleteKey')->willThrowException(new RuntimeException('db gone'));
		$markers = new UnarmedTimerMarkers(appConfig: $config);

		$this->assertSame([], $markers->all());
		$this->assertFalse($markers->add(itemUuid: 'item-a'));
		$markers->remove(itemUuid: 'item-a');

		$listing = $this->createMock(IAppConfig::class);
		$listing->method('getKeys')->willReturn([UnarmedTimerMarkers::KEY_PREFIX . 'item-a']);
		$listing->method('getValueString')->willThrowException(new RuntimeException('row vanished'));
		$this->assertSame([], (new UnarmedTimerMarkers(appConfig: $listing))->all());
	}//end testAnUnreadableAppConfigIsToleratedEverywhere()
}//end class
