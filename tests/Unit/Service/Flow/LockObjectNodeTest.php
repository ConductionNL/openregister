<?php

/**
 * The lock step: acquire, park, back off, and give up loudly.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use DateInterval;
use DateTime;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\Nodes\LockObjectNode;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\LockObjectNode
 */
final class LockObjectNodeTest extends TestCase {

	private const RUN = 'run-aaaaaaaa-0000-0000-0000-000000000001';

	private const OBJ = 'obj-11111111-2222-3333-4444-555555555555';

	private ObjectService $objects;

	private LockObjectNode $node;

	private FlowResumeState $state;

	/**
	 * Wire the node over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(originalClassName: ObjectService::class);
		// runAs MUST actually invoke the callable, or every call silently
		// returns null and the node appears to work while doing nothing.
		$this->objects->method('runAs')->willReturnCallback(
			static fn (IUser $user, callable $operation) => $operation()
		);

		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid !== 'alice') {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('isEnabled')->willReturn(true);
				return $user;
			}
		);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $p = []): string => $p === [] ? $text : vsprintf(str_replace('%s', '%s', $text), $p)
		);

		$this->node = new LockObjectNode(
			$this->objects,
			$users,
			$l10n,
			$this->createMock(IURLGenerator::class)
		);

		$this->state = new FlowResumeState();
	}//end setUp()

	/**
	 * A run context with this node's scoped resume slot.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(): array {
		return [
			'runUuid' => self::RUN,
			'runAs' => 'alice',
			FlowNodeResumeState::CONTEXT_KEY => $this->state->forNode('lock-1'),
		];
	}//end context()

	/**
	 * One item carrying an object uuid.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 */
	private function items(): array {
		return [FlowItems::item(json: ['uuid' => self::OBJ])];
	}//end items()

	// ---------------------------------------------------------------
	// The happy path.
	// ---------------------------------------------------------------

	/**
	 * The lock is taken FOR THE RUN, and the items pass through untouched.
	 *
	 * @return void
	 */
	public function testTheLockIsTakenForTheRun(): void {
		$seen = [];
		$this->objects->method('lockObject')->willReturnCallback(
			function (...$args) use (&$seen): array {
				// Named arguments arrive positionally in declaration order:
				// identifier, process, duration, advisory, runUuid, nodeId.
				$seen = $args;
				return ['uuid' => self::OBJ, 'locked' => []];
			}
		);

		$out = $this->node->execute($this->items(), [], $this->context());

		$this->assertSame(self::OBJ, $out[0][FlowItems::JSON]['uuid']);
		$this->assertSame(self::OBJ, $seen[0]);
		$this->assertSame(self::RUN, $seen[4], 'the lock was not scoped to the run');
		$this->assertSame('lock-1', $seen[5]);
	}//end testTheLockIsTakenForTheRun()

	/**
	 * A successful acquire leaves no wait state behind.
	 *
	 * @return void
	 */
	public function testASuccessfulAcquireRecordsNoAttempts(): void {
		$this->objects->method('lockObject')->willReturn(['uuid' => self::OBJ, 'locked' => []]);
		$this->node->execute($this->items(), [], $this->context());

		$this->assertNull($this->state->forNode('lock-1')->get(LockObjectNode::SLOT_ATTEMPTS));
	}//end testASuccessfulAcquireRecordsNoAttempts()

	/**
	 * An empty firing takes no lock and MUST NOT suspend the whole run.
	 *
	 * After a route, the un-taken branch is marked and its steps still fire
	 * with zero items. Suspending there would park a run on a branch it never
	 * took.
	 *
	 * @return void
	 */
	public function testAnEmptyFiringDoesNotSuspend(): void {
		$this->objects->expects($this->never())->method('lockObject');
		$this->assertSame([], $this->node->execute([], [], $this->context()));
	}//end testAnEmptyFiringDoesNotSuspend()

	// ---------------------------------------------------------------
	// Contention.
	// ---------------------------------------------------------------

	/**
	 * A contended lock parks the run, with a NON-NULL resume time.
	 *
	 * 🔴 A null resumeAt means "waiting on an external signal", and
	 * findAbandonedSignals() FAILS such a run after 14 days without ever
	 * waking it. A lock waits on a clock.
	 *
	 * @return void
	 */
	public function testAContendedLockParksTheRunWithAClock(): void {
		$this->objects->method('lockObject')->willThrowException(new LockedException('held by run B'));

		try {
			$this->node->execute($this->items(), [], $this->context());
			$this->fail('the node did not suspend');
		} catch (FlowSuspension $suspension) {
			$this->assertNotNull($suspension->getResumeAt(), 'a null resumeAt would be reaped, never woken');
			$this->assertGreaterThan(new DateTime(), $suspension->getResumeAt());
			$this->assertStringContainsString(self::OBJ, $suspension->getMessage());
		}
	}//end testAContendedLockParksTheRunWithAClock()

	/**
	 * The wait deadline is stamped ONCE and survives a retry.
	 *
	 * Recomputing it per attempt would reset the budget on every heartbeat,
	 * and the node would wait forever while appearing to have a bound.
	 *
	 * @return void
	 */
	public function testTheDeadlineIsNotRestartedByARetry(): void {
		$this->objects->method('lockObject')->willThrowException(new LockedException('held'));

		try {
			$this->node->execute($this->items(), ['waitSeconds' => 600], $this->context());
		} catch (FlowSuspension $e) {
			// Expected.
		}

		$first = $this->state->forNode('lock-1')->get(LockObjectNode::SLOT_DEADLINE);
		$this->assertNotNull($first);

		try {
			$this->node->execute($this->items(), ['waitSeconds' => 600], $this->context());
		} catch (FlowSuspension $e) {
			// Expected.
		}

		$this->assertSame($first, $this->state->forNode('lock-1')->get(LockObjectNode::SLOT_DEADLINE));
		$this->assertSame(2, $this->state->forNode('lock-1')->get(LockObjectNode::SLOT_ATTEMPTS));
	}//end testTheDeadlineIsNotRestartedByARetry()

	/**
	 * An exhausted budget FAILS, naming the holder, and does not break the lock.
	 *
	 * @return void
	 */
	public function testAnExhaustedBudgetFailsAndNamesTheHolder(): void {
		$this->objects->method('lockObject')->willThrowException(
			new LockedException('Object is locked by flow run ' . self::RUN)
		);

		// A deadline already in the past: the budget is spent.
		$this->state->forNode('lock-1')->set(
			LockObjectNode::SLOT_DEADLINE,
			(new DateTime())->sub(new DateInterval('PT10S'))->format('c')
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(self::RUN);
		$this->node->execute($this->items(), [], $this->context());
	}//end testAnExhaustedBudgetFailsAndNamesTheHolder()

	/**
	 * The retry never wakes AFTER the deadline.
	 *
	 * Overshooting would report the failure a whole backoff interval late.
	 *
	 * @return void
	 */
	public function testTheRetryNeverOvershootsTheDeadline(): void {
		$this->objects->method('lockObject')->willThrowException(new LockedException('held'));

		try {
			// A budget shorter than the retry floor.
			$this->node->execute($this->items(), ['waitSeconds' => 5], $this->context());
			$this->fail('the node did not suspend');
		} catch (FlowSuspension $suspension) {
			$deadline = new DateTime((string)$this->state->forNode('lock-1')->get(LockObjectNode::SLOT_DEADLINE));
			$this->assertLessThanOrEqual($deadline, $suspension->getResumeAt());
		}
	}//end testTheRetryNeverOvershootsTheDeadline()

	// ---------------------------------------------------------------
	// Refusals.
	// ---------------------------------------------------------------

	/**
	 * Without a resume slot the node refuses rather than waiting forever.
	 *
	 * @return void
	 */
	public function testNoResumeSlotIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('resume slot');
		$this->node->execute($this->items(), [], ['runUuid' => self::RUN, 'runAs' => 'alice']);
	}//end testNoResumeSlotIsRefused()

	/**
	 * A run that cannot identify itself does not take a run lock.
	 *
	 * A run-scoped lock with no run is a lock the predicate refuses everybody
	 * for and nobody can release.
	 *
	 * @return void
	 */
	public function testARunWithNoIdentityIsRefused(): void {
		$context = $this->context();
		unset($context['runUuid']);

		$this->expectException(RuntimeException::class);
		$this->node->execute($this->items(), [], $context);
	}//end testARunWithNoIdentityIsRefused()

	/**
	 * A run with no acting identity is refused.
	 *
	 * @return void
	 */
	public function testARunWithNoActingIdentityIsRefused(): void {
		$context = $this->context();
		unset($context['runAs']);

		$this->expectException(RuntimeException::class);
		$this->node->execute($this->items(), [], $context);
	}//end testARunWithNoActingIdentityIsRefused()

	/**
	 * An item with no resolvable target throws rather than locking nothing
	 * and reporting success.
	 *
	 * @return void
	 */
	public function testAnUnresolvableTargetThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->node->execute([FlowItems::item(json: ['name' => 'no uuid here'])], [], $this->context());
	}//end testAnUnresolvableTargetThrows()

	// ---------------------------------------------------------------
	// Config.
	// ---------------------------------------------------------------

	/**
	 * A wait budget that is not a positive number is refused at authoring time.
	 *
	 * @return void
	 */
	public function testANonPositiveWaitBudgetIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->validateConfig(['waitSeconds' => 0]);
	}//end testANonPositiveWaitBudgetIsRefused()

	/**
	 * A non-numeric duration is refused.
	 *
	 * @return void
	 */
	public function testANonNumericDurationIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->validateConfig(['duration' => 'soon']);
	}//end testANonNumericDurationIsRefused()

	/**
	 * An empty config is valid: every key has a default.
	 *
	 * @return void
	 */
	public function testAnEmptyConfigIsValid(): void {
		$this->node->validateConfig([]);
		$this->addToAssertionCount(1);
	}//end testAnEmptyConfigIsValid()

	/**
	 * Every form field writes a key the node declares.
	 *
	 * A field whose key is not in configKeys() is a control the node never
	 * reads: it validates, it renders, and it does nothing.
	 *
	 * @return void
	 */
	public function testEveryFormFieldWritesADeclaredConfigKey(): void {
		foreach ($this->node->configForm() as $field) {
			$this->assertContains((string)$field['key'], $this->node->configKeys());
		}
	}//end testEveryFormFieldWritesADeclaredConfigKey()
}//end class
