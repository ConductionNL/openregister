<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\CaseItemTransitionedEvent;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Listener\CaseItemTimerListener;
use OCA\OpenRegister\Service\Case\CasePlanItemTimers;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Tests\Unit\Service\Case\CaseFixtures;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Real CasePlanItemTimers over a mocked clock; the plan service is mocked.
 */
class CaseItemTimerListenerTest extends TestCase {

	/**
	 * Active arms, terminal cancels, an enforcing expiry terminates, anything
	 * else is ignored.
	 *
	 * @return void
	 */
	public function testTransitionsAndExpiriesReachTheRightVerb(): void {
		$clock = $this->createMock(FlowTimerService::class);
		$clock->expects($this->once())->method('arm')->willReturn(new FlowTimer());
		$clock->expects($this->once())->method('cancelForSubject')->willReturn(1);
		$plans = $this->createMock(CasePlanService::class);
		$plans->expects($this->once())->method('onTimerExpired')->with('item-5', 'timer-1')->willReturn(true);
		$listener = new CaseItemTimerListener(timers: new CasePlanItemTimers(timers: $clock, logger: new NullLogger()), plans: $plans);

		$active = CaseFixtures::row(id: 5, key: 'beoordeling', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$active->setDueAt(new \DateTime('+2 days'));
		$listener->handle(new CaseItemTransitionedEvent(item: $active, fromState: CaseItem::STATE_ENABLED));

		$done = CaseFixtures::row(id: 5, key: 'beoordeling', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_COMPLETED);
		$listener->handle(new CaseItemTransitionedEvent(item: $done, fromState: CaseItem::STATE_ACTIVE));

		$enabled = CaseFixtures::row(id: 6, key: 'x', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ENABLED);
		$listener->handle(new CaseItemTransitionedEvent(item: $enabled, fromState: CaseItem::STATE_AVAILABLE));

		$timer = new FlowTimer();
		$timer->setUuid('timer-1');
		$timer->setSubjectType(CasePlanItemTimers::SUBJECT_TYPE);
		$timer->setSubjectUuid('item-5');
		$timer->setPurpose(FlowTimer::PURPOSE_EXPIRY);
		$timer->setLegalEffect(FlowTimer::LEGAL_WETTELIJK);
		$timer->setOnExpiry(CasePlanItemTimers::ON_EXPIRY);
		$listener->handle(new FlowTimerFiredEvent(timer: $timer, kind: FlowTimerFiredEvent::KIND_EXPIRY, transition: 'expiry', rungKey: null, recipients: [], priority: null, message: null));
		$listener->handle(new FlowTimerFiredEvent(timer: $timer, kind: FlowTimerFiredEvent::KIND_RUNG, transition: 'rung', rungKey: 'r1', recipients: [], priority: null, message: null));
		$listener->handle(new Event());
	}//end testTransitionsAndExpiriesReachTheRightVerb()
}//end class
