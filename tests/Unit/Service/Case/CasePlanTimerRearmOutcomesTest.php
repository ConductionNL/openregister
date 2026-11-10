<?php

/**
 * Outcome tests for {@see \OCA\OpenRegister\Service\Case\CasePlanTimerRearm}:
 * every way one marked plan item settles (armed, enforced, dropped, failed),
 * which markers are removed and what the pass counts.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use DateTimeImmutable;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemMapperInterface;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapperInterface;
use OCA\OpenRegister\Service\Case\CasePlanItemTimers;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Case\CasePlanTimerRearm;
use OCA\OpenRegister\Service\Case\CaseRearmSources;
use OCA\OpenRegister\Service\Case\UnarmedTimerMarkers;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * CasePlanTimerRearmOutcomesTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class CasePlanTimerRearmOutcomesTest extends TestCase {
	private UnarmedTimerMarkers&MockObject $markers;
	private CaseItemMapperInterface&MockObject $items;
	private FlowTimerMapperInterface&MockObject $timerRows;
	private CasePlanItemTimers&MockObject $timers;
	private CasePlanService&MockObject $plans;
	private LoggerInterface&MockObject $logger;
	/** @var list<string> */
	private array $removed = [];

	protected function setUp(): void {
		$this->markers = $this->createMock(UnarmedTimerMarkers::class);
		$this->markers->method('remove')->willReturnCallback(function (string $itemUuid): void {
			$this->removed[] = $itemUuid;
		});
		$this->items = $this->createMock(CaseItemMapperInterface::class);
		$this->timerRows = $this->createMock(FlowTimerMapperInterface::class);
		$this->timers = $this->createMock(CasePlanItemTimers::class);
		$this->plans = $this->createMock(CasePlanService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function settlePass(array $marked): array {
		$this->markers->method('all')->willReturn(array_fill_keys($marked, '2026-01-01T00:00:00+00:00'));
		$pass = new CasePlanTimerRearm(
			new CaseRearmSources($this->markers, $this->items, $this->timerRows),
			$this->timers,
			$this->plans,
			$this->logger
		);

		return $pass->run(new DateTimeImmutable('2026-02-01T00:00:00+00:00'));
	}

	private function item(string $uuid, string $state = CaseItem::STATE_ACTIVE): CaseItem {
		$item = new CaseItem();
		$item->setUuid($uuid);
		$item->setState($state);
		return $item;
	}

	private function timer(string $purpose): FlowTimer {
		$timer = new FlowTimer();
		$timer->setPurpose($purpose);
		return $timer;
	}

	public function testAnArmedItemCountsAsArmedAndLosesItsMarker(): void {
		$item = $this->item('i-1');
		$this->items->method('findByUuid')->with('i-1')->willReturn($item);
		$this->timerRows->method('findBySubject')
			->with(CasePlanItemTimers::SUBJECT_TYPE, 'i-1', [FlowTimer::STATE_ARMED, FlowTimer::STATE_SUSPENDED])
			->willReturn([$this->timer(FlowTimer::PURPOSE_DUE)]);
		$this->timers->expects($this->once())->method('rearmStatutoryExpiry')
			->with($item, $this->callback(static fn ($act): bool => $act->actor === null && $act->now->format('Y-m-d') === '2026-02-01'))
			->willReturn(CasePlanItemTimers::REARM_ARMED);

		$this->assertSame(['armed' => 1, 'enforced' => 0, 'dropped' => 0, 'failed' => 0], $this->settlePass(['i-1']));
		$this->assertSame(['i-1'], $this->removed);
	}

	public function testAnItemWithNothingToArmIsDropped(): void {
		$this->items->method('findByUuid')->willReturn($this->item('i-1'));
		$this->timerRows->method('findBySubject')->willReturn([]);
		$this->timers->method('rearmStatutoryExpiry')->willReturn(CasePlanItemTimers::REARM_NONE);

		$this->assertSame(1, $this->settlePass(['i-1'])['dropped']);
		$this->assertSame(['i-1'], $this->removed);
	}

	public function testARefusedArmIsFailedAndKeepsTheMarker(): void {
		$this->items->method('findByUuid')->willReturn($this->item('i-1'));
		$this->timerRows->method('findBySubject')->willReturn([]);
		$this->timers->method('rearmStatutoryExpiry')->willReturn(CasePlanItemTimers::REARM_FAILED);

		$this->assertSame(1, $this->settlePass(['i-1'])['failed']);
		$this->assertSame([], $this->removed);
	}

	public function testAnOverdueItemIsEnforcedThroughThePlanAndLosesItsMarker(): void {
		$this->items->method('findByUuid')->willReturn($this->item('i-1'));
		$this->timerRows->method('findBySubject')->willReturn([]);
		$this->timers->method('rearmStatutoryExpiry')->willReturn(CasePlanItemTimers::REARM_OVERDUE);
		$this->plans->expects($this->once())->method('onTimerExpired')
			->with('i-1', CasePlanTimerRearm::CAUSE_REF)->willReturn(true);

		$this->assertSame(1, $this->settlePass(['i-1'])['enforced']);
		$this->assertSame(['i-1'], $this->removed);
	}

	public function testARefusedEnforcementOfAnItemThatVanishedIsDropped(): void {
		$this->items->method('findByUuid')->willReturnOnConsecutiveCalls(
			$this->item('i-1'),
			$this->throwException(new DoesNotExistException('gone'))
		);
		$this->timerRows->method('findBySubject')->willReturn([]);
		$this->timers->method('rearmStatutoryExpiry')->willReturn(CasePlanItemTimers::REARM_OVERDUE);
		$this->plans->method('onTimerExpired')->willReturn(false);

		$this->assertSame(1, $this->settlePass(['i-1'])['dropped']);
		$this->assertSame(['i-1'], $this->removed);
	}

	public function testARefusedEnforcementOfAStillActiveItemIsFailedAndKeepsTheMarker(): void {
		$this->items->method('findByUuid')->willReturn($this->item('i-1'));
		$this->timerRows->method('findBySubject')->willReturn([]);
		$this->timers->method('rearmStatutoryExpiry')->willReturn(CasePlanItemTimers::REARM_OVERDUE);
		$this->plans->method('onTimerExpired')->willReturn(false);

		$this->assertSame(1, $this->settlePass(['i-1'])['failed']);
		$this->assertSame([], $this->removed);
	}

	public function testAMissingItemIsDroppedWithoutArming(): void {
		$this->items->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		$this->timers->expects($this->never())->method('rearmStatutoryExpiry');

		$this->assertSame(1, $this->settlePass(['i-1'])['dropped']);
		$this->assertSame(['i-1'], $this->removed);
	}

	public function testAnInactiveItemIsDroppedWithoutLookingForTimers(): void {
		$this->items->method('findByUuid')->willReturn($this->item('i-1', CaseItem::STATE_COMPLETED));
		$this->timerRows->expects($this->never())->method('findBySubject');
		$this->timers->expects($this->never())->method('rearmStatutoryExpiry');

		$this->assertSame(1, $this->settlePass(['i-1'])['dropped']);
	}

	public function testAnItemThatStillHasAnOpenExpiryTimerIsDropped(): void {
		$this->items->method('findByUuid')->willReturn($this->item('i-1'));
		$this->timerRows->method('findBySubject')->willReturn([$this->timer(FlowTimer::PURPOSE_DUE), $this->timer(FlowTimer::PURPOSE_EXPIRY)]);
		$this->timers->expects($this->never())->method('rearmStatutoryExpiry');

		$this->assertSame(1, $this->settlePass(['i-1'])['dropped']);
	}

	public function testAThrowingItemIsLoggedAsFailedAndTheRestOfThePassContinues(): void {
		$this->items->method('findByUuid')->willReturnCallback(function (string $uuid): CaseItem {
			if ($uuid === 'bad') {
				throw new RuntimeException('db down');
			}
			return $this->item($uuid);
		});
		$this->timerRows->method('findBySubject')->willReturn([]);
		$this->timers->method('rearmStatutoryExpiry')->willReturn(CasePlanItemTimers::REARM_ARMED);
		$this->logger->expects($this->once())->method('error')->with(
			'[CasePlanTimerRearm] Plan item bad still lacks its statutory expiry timer: db down',
			$this->callback(static fn (array $context): bool => $context['item'] === 'bad')
		);

		$this->assertSame(['armed' => 1, 'enforced' => 0, 'dropped' => 0, 'failed' => 1], $this->settlePass(['bad', 'good']));
		$this->assertSame(['good'], $this->removed);
	}

	public function testAnEmptyMarkerStoreSettlesNothing(): void {
		$this->items->expects($this->never())->method('findByUuid');

		$this->assertSame(['armed' => 0, 'enforced' => 0, 'dropped' => 0, 'failed' => 0], $this->settlePass([]));
	}
}
