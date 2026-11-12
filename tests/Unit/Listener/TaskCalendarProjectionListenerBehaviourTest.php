<?php

/**
 * Task transitions are projected to the calendar; other events are not.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Event\TaskTransitionedEvent;
use OCA\OpenRegister\Listener\TaskCalendarProjectionListener;
use OCA\OpenRegister\Service\Task\TaskProjectionServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Forwards exactly the transition event to the projection service.
 */
class TaskCalendarProjectionListenerBehaviourTest extends TestCase {
	public function testATransitionIsHandedToTheProjectionService(): void {
		$event       = new TaskTransitionedEvent(new Task());
		$projections = $this->createMock(TaskProjectionServiceInterface::class);
		$projections->expects($this->once())->method('afterTransition')->with($this->identicalTo($event));

		(new TaskCalendarProjectionListener($projections))->handle($event);
	}

	public function testAnotherTaskEventIsNotProjected(): void {
		$projections = $this->createMock(TaskProjectionServiceInterface::class);
		$projections->expects($this->never())->method('afterTransition');

		(new TaskCalendarProjectionListener($projections))->handle(new TaskTerminalEvent(new Task()));
	}
}
