<?php

/**
 * The referenced-calculation listener decides inline whether a refresh is
 * needed and queues the re-saves as ReferencedCalculationJob (ADR-078); it
 * re-saves inline only under the deferral kill switch.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\BackgroundJob\ReferencedCalculationJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\ReferencedCalculationListener;
use OCA\OpenRegister\Service\Calculation\ReferencedCalculationRefresher;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Listener\ReferencedCalculationListener
 */
class ReferencedCalculationListenerTest extends TestCase {

	private ReferencedCalculationRefresher&MockObject $refresher;

	private ListenerDeferralService&MockObject $deferral;

	protected function setUp(): void {
		parent::setUp();
		$this->refresher = $this->createMock(ReferencedCalculationRefresher::class);
		$this->deferral  = $this->createMock(ListenerDeferralService::class);
	}//end setUp()

	private function event(): ObjectUpdatedEvent {
		$new = new ObjectEntity();
		$new->setUuid('type-1');
		$new->setRegister('7');
		$new->setSchema('1');
		$new->setObject(['startableFlows' => ['f1']]);

		$old = new ObjectEntity();
		$old->setUuid('type-1');
		$old->setObject(['startableFlows' => []]);

		return new ObjectUpdatedEvent($new, $old);
	}//end event()

	private function listener(): ReferencedCalculationListener {
		return new ReferencedCalculationListener(refresher: $this->refresher, logger: new NullLogger(), deferral: $this->deferral);
	}//end listener()

	public function testARefreshIsQueuedAndNoReferrerIsReSavedInTheRequest(): void {
		$this->refresher->method('changedReadFields')->willReturn(['startableFlows']);
		$this->refresher->method('depth')->willReturn(0);
		$this->deferral->method('isDeferralEnabled')->willReturn(true);

		$this->refresher->expects($this->never())->method('refreshChanged');
		$this->refresher->expects($this->never())->method('refresh');
		$this->deferral->expects($this->once())->method('defer')->willReturnCallback(
			function (string $jobClass, array $entry): void {
				$this->assertSame(ReferencedCalculationJob::class, $jobClass);
				$this->assertSame('type-1', $entry['uuid']);
				$this->assertSame('7', $entry['register']);
				$this->assertSame('1', $entry['schema']);
				$this->assertSame(['startableFlows'], $entry['changed']);
				$this->assertSame(0, $entry['depth']);
			}
		);

		$this->listener()->handle($this->event());
	}//end testARefreshIsQueuedAndNoReferrerIsReSavedInTheRequest()

	public function testAnUnreadChangeQueuesNothing(): void {
		$this->refresher->method('changedReadFields')->willReturn([]);
		$this->deferral->expects($this->never())->method('defer');
		$this->refresher->expects($this->never())->method('refreshChanged');

		$this->listener()->handle($this->event());
	}//end testAnUnreadChangeQueuesNothing()

	public function testTheKillSwitchRefreshesInline(): void {
		$this->refresher->method('changedReadFields')->willReturn(['startableFlows']);
		$this->refresher->method('depth')->willReturn(1);
		$this->deferral->method('isDeferralEnabled')->willReturn(false);

		$this->deferral->expects($this->never())->method('defer');
		$this->refresher->expects($this->once())->method('refreshChanged')
			->with($this->anything(), ['startableFlows'], 1)
			->willReturn(3);

		$this->listener()->handle($this->event());
	}//end testTheKillSwitchRefreshesInline()
}//end class
