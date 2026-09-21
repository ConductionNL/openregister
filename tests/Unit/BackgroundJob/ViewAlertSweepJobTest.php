<?php

/**
 * Unit tests for the view-alert sweep.
 *
 * The sweep's job is to be boring: count what is due, decide with one rule, say
 * so once. The tests that matter are the ones about what it must NOT do — count
 * as the system, page twice, or stop the pass because one view is broken.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\ViewAlertSweepJob;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Event\ViewAlertCrossedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\View\ViewAlert;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ViewAlertSweepJobTest extends TestCase {

	private ViewMapper&MockObject $views;

	private ObjectService&MockObject $objects;

	private IUserManager&MockObject $users;

	private ViewAlertSweepJob $job;

	/**
	 * Events the pass dispatched.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	protected function setUp(): void {
		parent::setUp();

		$this->views = $this->createMock(ViewMapper::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->dispatched = [];

		// runAs() is the whole point of the count, so the double RUNS the
		// callable rather than pretending. A test that stubbed it away would
		// pass with the identity ignored, which is the one thing here that
		// must not be ignorable.
		$this->objects->method('runAs')->willReturnCallback(
			static function (IUser $user, callable $operation) {
				return $operation();
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->dispatched[] = $event;
			}
		);

		$this->users->method('get')->willReturn($this->createMock(IUser::class));

		$this->job = new ViewAlertSweepJob(
			$this->createMock(ITimeFactory::class),
			$this->views,
			$this->objects,
			$this->users,
			$dispatcher,
			new NullLogger()
		);
	}//end setUp()

	/**
	 * A view with an alert. Entity getters are magic, so this is a real one.
	 *
	 * @param array       $alert     The declaration.
	 * @param array|null  $state     The stored state.
	 * @param string|null $evaluated When it was last evaluated.
	 *
	 * @return View
	 */
	private function view(array $alert, ?array $state = null, ?string $evaluated = null): View {
		$view = new View();
		$view->setUuid('view-1');
		$view->setName('Open cases');
		$view->setOwner('anna');
		$view->setQuery(['_register' => 3, '_schema' => 5]);
		$view->setAlert($alert);
		$view->setAlertState($state);
		if ($evaluated !== null) {
			$view->setAlertEvaluatedAt(new DateTime($evaluated));
		}

		return $view;
	}//end view()

	/**
	 * A standard declaration.
	 *
	 * @return array The alert.
	 */
	private function alert(): array {
		return ['operator' => 'gte', 'threshold' => 20, 'recipients' => ['teamlead'], 'every' => 900];
	}//end alert()

	/**
	 * Run one pass over the given views.
	 *
	 * @param array $views The views the mapper answers with.
	 *
	 * @return void
	 */
	private function sweep(array $views): void {
		$this->views->method('findWithAlerts')->willReturn($views);
		$this->pass($this->job);
	}//end sweep()

	/**
	 * Invoke the job's protected run().
	 *
	 * @param ViewAlertSweepJob $job The job.
	 *
	 * @return void
	 */
	private function pass(ViewAlertSweepJob $job): void {
		$method = new \ReflectionMethod(ViewAlertSweepJob::class, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}//end pass()

	/**
	 * A crossing is counted, stored and announced once.
	 *
	 * @return void
	 */
	public function testACrossingIsStoredAndAnnounced(): void {
		$view = $this->view($this->alert());
		$this->objects->method('count')->willReturn(23);
		$this->views->expects($this->once())->method('update');

		$this->sweep([$view]);

		$this->assertCount(1, $this->dispatched);
		$this->assertInstanceOf(ViewAlertCrossedEvent::class, $this->dispatched[0]);
		$this->assertSame(23, $this->dispatched[0]->getCount());
		$this->assertSame(ViewAlert::FIRED, $view->getAlertState()['state']);
		$this->assertSame(23, $view->getAlertState()['lastCount']);
	}//end testACrossingIsStoredAndAnnounced()

	/**
	 * 🔴 A STANDING BACKLOG SAYS NOTHING THE SECOND TIME. The view is already
	 * `fired`, the count is still over, and nobody is told again.
	 *
	 * @return void
	 */
	public function testAViewAlreadyFiredSaysNothingAgain(): void {
		$this->objects->method('count')->willReturn(23);

		$this->sweep([$this->view($this->alert(), ['state' => ViewAlert::FIRED, 'lastCount' => 23])]);

		$this->assertSame([], $this->dispatched);
	}//end testAViewAlreadyFiredSaysNothingAgain()

	/**
	 * The count is taken as the view's OWNER.
	 *
	 * A shared view alerts on what its owner may see. Counting as the system
	 * would turn a threshold on a shared view into a way to learn how many
	 * records sit behind a filter the reader is not entitled to.
	 *
	 * @return void
	 */
	public function testTheCountIsTakenAsTheOwner(): void {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('anna');

		$users = $this->createMock(IUserManager::class);
		$users->expects($this->once())->method('get')->with('anna')->willReturn($owner);

		$seen = null;
		$objects = $this->createMock(ObjectService::class);
		$objects->method('runAs')->willReturnCallback(
			static function (IUser $user, callable $operation) use (&$seen) {
				$seen = $user->getUID();
				return $operation();
			}
		);
		$objects->method('count')->willReturn(23);

		$views = $this->createMock(ViewMapper::class);
		$views->method('findWithAlerts')->willReturn([$this->view($this->alert())]);

		$this->pass(
			new ViewAlertSweepJob(
				$this->createMock(ITimeFactory::class),
				$views,
				$objects,
				$users,
				$this->createMock(IEventDispatcher::class),
				new NullLogger()
			)
		);

		$this->assertSame('anna', $seen);
	}//end testTheCountIsTakenAsTheOwner()

	/**
	 * A view whose owner is gone is skipped, not counted as the system.
	 *
	 * @return void
	 */
	public function testAViewWithNoOwnerIsSkippedRatherThanCountedAsTheSystem(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn(null);

		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('count');

		$views = $this->createMock(ViewMapper::class);
		$views->method('findWithAlerts')->willReturn([$this->view($this->alert())]);

		$this->pass(
			new ViewAlertSweepJob(
				$this->createMock(ITimeFactory::class),
				$views,
				$objects,
				$users,
				$this->createMock(IEventDispatcher::class),
				new NullLogger()
			)
		);

		$this->addToAssertionCount(1);
	}//end testAViewWithNoOwnerIsSkippedRatherThanCountedAsTheSystem()

	/**
	 * A view inside its interval is not counted at all.
	 *
	 * @return void
	 */
	public function testAViewInsideItsIntervalIsNotCounted(): void {
		$this->objects->expects($this->never())->method('count');

		$this->sweep([$this->view($this->alert(), null, 'now')]);

		$this->assertSame([], $this->dispatched);
	}//end testAViewInsideItsIntervalIsNotCounted()

	/**
	 * One unreadable declaration does not stop the pass.
	 *
	 * The views after it in the batch are exactly the ones that would never be
	 * evaluated again, because the watermark orders by last evaluation.
	 *
	 * @return void
	 */
	public function testAnUnreadableAlertDoesNotStopThePass(): void {
		$broken = $this->view(['operator' => 'above', 'threshold' => 10]);
		$good = $this->view($this->alert());
		$this->objects->method('count')->willReturn(23);

		$this->sweep([$broken, $good]);

		$this->assertCount(1, $this->dispatched, 'the good view was still evaluated');
	}//end testAnUnreadableAlertDoesNotStopThePass()

	/**
	 * A count that cannot be taken leaves the state alone rather than re-arming.
	 *
	 * Treating a failed count as "below the threshold" would silently re-arm a
	 * fired alert and page somebody again the moment counting worked.
	 *
	 * @return void
	 */
	public function testAFailedCountLeavesTheStateAlone(): void {
		$view = $this->view($this->alert(), ['state' => ViewAlert::FIRED, 'lastCount' => 23]);
		$this->objects->method('count')->willThrowException(new \RuntimeException('no database'));
		$this->views->expects($this->never())->method('update');

		$this->sweep([$view]);

		$this->assertSame(ViewAlert::FIRED, $view->getAlertState()['state']);
		$this->assertSame([], $this->dispatched);
	}//end testAFailedCountLeavesTheStateAlone()

	/**
	 * The pass is bounded, and the bound is a real number rather than a hope.
	 *
	 * @return void
	 */
	public function testThePassIsBounded(): void {
		$this->views->expects($this->once())
			->method('findWithAlerts')
			->with(ViewAlertSweepJob::BATCH)
			->willReturn([]);

		$this->pass($this->job);

		$this->assertLessThanOrEqual(500, ViewAlertSweepJob::BATCH);
	}//end testThePassIsBounded()
}//end class
