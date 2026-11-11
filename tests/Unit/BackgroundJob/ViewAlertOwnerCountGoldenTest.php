<?php

declare(strict_types=1);

/**
 * Golden of the owner-scoped count in ViewAlertSweepJob: who the count runs
 * as, when it is refused, and everything it logs, stores and announces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\Support\ViewAlertSweepPlatform;
use OCA\OpenRegister\BackgroundJob\Support\ViewAlertSweepSources;
use OCA\OpenRegister\BackgroundJob\ViewAlertSweepJob;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\View\ViewObjectQuery;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Pins allowed and refused owner-scoped counts, with their full trail.
 */
class ViewAlertOwnerCountGoldenTest extends TestCase {

	/**
	 * Everything observable, in order.
	 *
	 * @var array<int, string>
	 */
	private array $trail = [];

	public function testTheCountRunsAsTheOwnerAndAFiringIsStoredAndAnnounced(): void {
		$view = $this->sweep(owner: 'anna', query: ['_register' => 3, '_schema' => 5], count: 23);

		$this->assertSame(
			['user anna', 'runAs anna', 'count', 'update fired 23', 'dispatch 23', 'info [ViewAlertSweepJob] {count} view alerts crossed 1'],
			$this->trail
		);
		$this->assertSame('fired', $view->getAlertState()['state']);
	}//end testTheCountRunsAsTheOwnerAndAFiringIsStoredAndAnnounced()

	public function testABelowThresholdCountIsStoredButNotAnnounced(): void {
		$this->sweep(owner: 'anna', query: ['_register' => 3, '_schema' => 5], count: 4);

		$this->assertSame(['user anna', 'runAs anna', 'count', 'update armed 4'], $this->trail);
	}//end testABelowThresholdCountIsStoredButNotAnnounced()

	public function testAMissingOwnerIsRefusedBeforeAnyCount(): void {
		$view = $this->sweep(owner: 'ghost', query: ['_register' => 3, '_schema' => 5], count: 23);

		$this->assertSame(
			[
				'user ghost',
				'warning [ViewAlertSweepJob] View {view} has no resolvable owner, so its count has no entitlement to be measured against view-1',
			],
			$this->trail
		);
		$this->assertNull($view->getAlertState());
	}//end testAMissingOwnerIsRefusedBeforeAnyCount()

	public function testAViewThatBoundsNothingIsRefusedBeforeAnyCount(): void {
		$this->sweep(owner: 'anna', query: ['status' => 'open'], count: 23);

		$this->assertSame(
			[
				'user anna',
				'warning [ViewAlertSweepJob] View {view} names no register or schema, so its alert has nothing to count view-1',
			],
			$this->trail
		);
	}//end testAViewThatBoundsNothingIsRefusedBeforeAnyCount()

	public function testAFailedCountStoresNothing(): void {
		$this->sweep(owner: 'anna', query: ['_register' => 3, '_schema' => 5], count: -1);

		$this->assertSame(
			[
				'user anna',
				'runAs anna',
				'count',
				'warning [ViewAlertSweepJob] Could not count view {view}: {error} view-1 search down',
			],
			$this->trail
		);
	}//end testAFailedCountStoresNothing()

	/**
	 * Run one pass over one due view.
	 *
	 * @param string               $owner The view's owner; "ghost" does not resolve.
	 * @param array<string, mixed> $query The view's query.
	 * @param int                  $count What the count answers; -1 throws.
	 *
	 * @return View The view after the pass.
	 */
	private function sweep(string $owner, array $query, int $count): View {
		$this->trail = [];

		$view = new View();
		$view->setUuid('view-1');
		$view->setName('Open cases');
		$view->setOwner($owner);
		$view->setQuery($query);
		$view->setAlert(['operator' => 'gte', 'threshold' => 20, 'recipients' => ['teamlead'], 'every' => 900]);
		$view->setAlertState(null);

		$views = $this->createMock(ViewMapper::class);
		$views->method('findWithAlerts')->willReturn([$view]);
		$views->method('update')->willReturnCallback(
			function (View $updated): View {
				$state         = $updated->getAlertState();
				$this->trail[] = 'update ' . $state['state'] . ' ' . $state['lastCount'];
				return $updated;
			}
		);

		$job = new ViewAlertSweepJob(
			$this->createMock(ITimeFactory::class),
			new ViewAlertSweepSources($views, $this->objects(count: $count), new ViewObjectQuery()),
			new ViewAlertSweepPlatform($this->users(), $this->dispatcher(), $this->logger())
		);

		$method = new \ReflectionMethod(ViewAlertSweepJob::class, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);

		return $view;
	}//end sweep()

	/**
	 * An object service whose runAs() records who and runs the count.
	 *
	 * @param int $count What the count answers; -1 throws.
	 *
	 * @return ObjectService
	 */
	private function objects(int $count): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation) {
				$this->trail[] = 'runAs ' . $user->getUID();
				return $operation();
			}
		);
		$objects->method('countSearchObjects')->willReturnCallback(
			function () use ($count): int {
				$this->trail[] = 'count';
				if ($count < 0) {
					throw new RuntimeException('search down');
				}

				return $count;
			}
		);
		return $objects;
	}//end objects()

	/**
	 * A user manager that knows anna only.
	 *
	 * @return IUserManager
	 */
	private function users(): IUserManager {
		$anna = $this->createMock(IUser::class);
		$anna->method('getUID')->willReturn('anna');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid) use ($anna): ?IUser {
				$this->trail[] = 'user ' . $uid;
				if ($uid === 'anna') {
					return $anna;
				}

				return null;
			}
		);
		return $users;
	}//end users()

	/**
	 * A dispatcher that records the announced count.
	 *
	 * @return IEventDispatcher
	 */
	private function dispatcher(): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->trail[] = 'dispatch ' . $event->getCount();
			}
		);
		return $dispatcher;
	}//end dispatcher()

	/**
	 * A logger that writes to the trail.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$trail = &$this->trail;
		return new class($trail) extends AbstractLogger {
			/**
			 * @param array<int, string> $trail The shared trail.
			 */
			public function __construct(private array &$trail) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->trail[] = trim($level . ' ' . $message . ' ' . implode(' ', array_map('strval', $context)));
			}
		};
	}//end logger()
}//end class
