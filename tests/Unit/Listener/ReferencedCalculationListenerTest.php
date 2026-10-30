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
use OCP\IUser;
use OCP\IUserSession;
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
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ReferencedCalculationListener(refresher: $this->refresher, logger: new NullLogger(), deferral: $this->deferral, session: $session);
	}//end listener()

	public function testARefreshIsQueuedAndNoReferrerIsReSavedInTheRequest(): void {
		$this->refresher->method('changedReadFields')->willReturn(['startableFlows']);
		$this->refresher->method('depth')->willReturn(0);
		$this->deferral->method('isDeferralEnabled')->willReturn(true);

		$this->refresher->expects($this->never())->method('refreshChanged');
		$this->refresher->expects($this->never())->method('refresh');
		// Captured, then asserted outside: the listener swallows whatever its
		// collaborators throw, an assertion failure included.
		$calls = [];
		$this->deferral->expects($this->once())->method('defer')->willReturnCallback(
			static function (string $jobClass, array $entry, int $chunkSize, ?string $dedupeKey) use (&$calls): void {
				$calls[] = [$jobClass, $entry, $dedupeKey];
			}
		);

		$this->listener()->handle($this->event());

		[$jobClass, $entry, $dedupeKey] = $calls[0];
		$this->assertSame(ReferencedCalculationJob::class, $jobClass);
		$this->assertSame('type-1', $entry['uuid']);
		$this->assertSame('7', $entry['register']);
		$this->assertSame('1', $entry['schema']);
		$this->assertSame(['startableFlows'], $entry['changed']);
		$this->assertSame(0, $entry['depth']);
		$this->assertSame('alice', $entry['actor'], 'the entry names the user of the update');
		$this->assertSame('type-1|startableFlows', $dedupeKey);
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
		$calls = [];
		$this->refresher->expects($this->once())->method('refreshChanged')->willReturnCallback(
			static function (mixed $updated, array $changed, int $depth) use (&$calls): int {
				$calls[] = [$changed, $depth];
				return 3;
			}
		);

		$this->listener()->handle($this->event());

		$this->assertSame([[['startableFlows'], 1]], $calls);
	}//end testTheKillSwitchRefreshesInline()
}//end class
