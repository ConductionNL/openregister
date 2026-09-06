<?php

declare(strict_types=1);

namespace Unit\Service\Deferral;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Tests\Support\ShutdownFunctionSpy;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../../Support/ShutdownFunctionSpy.php';

/**
 * Chunked buffering, dedupe coalescing, kill switch and fail-soft capture.
 *
 * The service's shutdown registration is intercepted by ShutdownFunctionSpy,
 * so no test leaves a live shutdown callback behind and the registration
 * itself is asserted on rather than assumed.
 */
class ListenerDeferralServiceTest extends TestCase {
	private IUserSession&MockObject $userSession;
	private OrganisationService&MockObject $organisation;
	private IJobList&MockObject $jobList;
	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		ShutdownFunctionSpy::reset();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->organisation = $this->createMock(OrganisationService::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function makeService(): ListenerDeferralService {
		return new ListenerDeferralService(
			userSession: $this->userSession,
			organisation: $this->organisation,
			jobList: $this->jobList,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);
	}

	private function actAs(?string $uid, ?string $orgUuid): void {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$this->userSession->method('getUser')->willReturn($user);

		$org = null;
		if ($orgUuid !== null) {
			$org = new Organisation();
			$org->setUuid($orgUuid);
		}

		$this->organisation->method('getActiveOrganisation')->willReturn($org);
	}

	public function testFlushAllEnqueuesOneChunkJobWithCapturedActor(): void {
		$this->actAs('alice', 'org-1');
		$service = $this->makeService();

		$captured = [];
		$this->jobList->expects($this->once())->method('add')
			->willReturnCallback(function (string $jobClass, $argument) use (&$captured): void {
				$captured = ['jobClass' => $jobClass, 'argument' => $argument];
			});

		$service->defer(jobClass: 'Some\\Job', entry: ['uuid' => 'a']);
		$service->defer(jobClass: 'Some\\Job', entry: ['uuid' => 'b']);
		$service->flushAll();

		$this->assertSame('Some\\Job', $captured['jobClass']);
		$this->assertSame('alice', $captured['argument']['userId']);
		$this->assertSame('org-1', $captured['argument']['organisationUuid']);
		$this->assertSame([['uuid' => 'a'], ['uuid' => 'b']], $captured['argument']['entries']);
	}

	public function testFullChunksFlushImmediatelyAndRemainderAtFlushAll(): void {
		$this->actAs('alice', null);
		$service = $this->makeService();

		$batches = [];
		$this->jobList->expects($this->exactly(3))->method('add')
			->willReturnCallback(function (string $jobClass, $argument) use (&$batches): void {
				$batches[] = array_column($argument['entries'], 'uuid');
			});

		foreach (['a', 'b', 'c', 'd', 'e'] as $uuid) {
			$service->defer(jobClass: 'Some\\Job', entry: ['uuid' => $uuid], chunkSize: 2);
		}

		// Two full chunks flushed inline; the remainder waits for shutdown.
		$this->assertSame([['a', 'b'], ['c', 'd']], $batches);

		$service->flushAll();
		$this->assertSame([['a', 'b'], ['c', 'd'], ['e']], $batches);

		// A second flush must not re-enqueue anything (buffer was reset).
		$service->flushAll();
	}

	public function testDedupeKeyCoalescesEntriesWithinAChunk(): void {
		$this->actAs('alice', null);
		$service = $this->makeService();

		$entries = [];
		$this->jobList->expects($this->once())->method('add')
			->willReturnCallback(function (string $jobClass, $argument) use (&$entries): void {
				$entries = $argument['entries'];
			});

		for ($i = 0; $i < 50; $i++) {
			$service->defer(
				jobClass: 'Some\\Job',
				entry: ['uuid' => 'obj-' . $i, 'register' => 'r', 'schema' => 's'],
				dedupeKey: 'r|s',
			);
		}

		$service->flushAll();

		$this->assertCount(1, $entries);
		$this->assertSame('obj-0', $entries[0]['uuid']);
	}

	public function testBuffersAreIsolatedPerJobClass(): void {
		$this->actAs(null, null);
		$service = $this->makeService();

		$jobs = [];
		$this->jobList->expects($this->exactly(2))->method('add')
			->willReturnCallback(function (string $jobClass, $argument) use (&$jobs): void {
				$jobs[$jobClass] = count($argument['entries']);
			});

		$service->defer(jobClass: 'Job\\A', entry: ['uuid' => 'x']);
		$service->defer(jobClass: 'Job\\B', entry: ['uuid' => 'x']);
		$service->flushAll();

		$this->assertSame(['Job\\A' => 1, 'Job\\B' => 1], $jobs);
	}

	public function testKillSwitchDisablesDeferral(): void {
		$this->appConfig->method('getValueString')
			->with('openregister', 'listenerDeferral', 'background')
			->willReturn('inline');

		$this->assertFalse($this->makeService()->isDeferralEnabled());
	}

	public function testDeferralEnabledByDefault(): void {
		$this->appConfig->method('getValueString')->willReturn('background');
		$this->assertTrue($this->makeService()->isDeferralEnabled());
	}

	public function testOrganisationCaptureFailureIsSoft(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->organisation->method('getActiveOrganisation')->willThrowException(new \RuntimeException('db down'));

		$service = $this->makeService();

		$argument = null;
		$this->jobList->expects($this->once())->method('add')
			->willReturnCallback(function (string $jobClass, $arg) use (&$argument): void {
				$argument = $arg;
			});

		$service->defer(jobClass: 'Some\\Job', entry: ['uuid' => 'a']);
		$service->flushAll();

		$this->assertSame('alice', $argument['userId']);
		$this->assertNull($argument['organisationUuid']);
	}

	// ─── Shutdown registration ───────────────────────────────────────

	/**
	 * Buffering an entry registers the shutdown flush, exactly once per
	 * request no matter how many entries or job classes follow.
	 *
	 * Without this the deferral is a black hole: `defer()` buffers, nothing
	 * ever flushes, and every other test in this file still passes because
	 * they all call `flushAll()` by hand.
	 */
	public function testDeferRegistersTheShutdownFlushExactlyOnce(): void {
		$this->actAs('alice', 'org-1');
		$service = $this->makeService();

		// Well under the default chunk size, so only the shutdown flush can
		// ever enqueue these.
		$this->jobList->expects($this->never())->method('add');

		$service->defer(jobClass: 'Some\\Job', entry: ['uuid' => 'a']);
		$service->defer(jobClass: 'Some\\Job', entry: ['uuid' => 'b']);
		$service->defer(jobClass: 'Other\\Job', entry: ['uuid' => 'c']);

		$this->assertCount(1, ShutdownFunctionSpy::callbacks());
	}

	/**
	 * The callback handed to the shutdown queue really is the flush: running
	 * it enqueues the buffered work. Proves the registration points at
	 * flushAll() and not at some other or stale callable.
	 */
	public function testRegisteredShutdownCallbackEnqueuesTheBufferedWork(): void {
		$this->actAs('alice', 'org-1');
		$service = $this->makeService();

		$enqueued = [];
		$this->jobList->method('add')
			->willReturnCallback(function (string $jobClass, $argument) use (&$enqueued): void {
				$enqueued[] = ['jobClass' => $jobClass, 'argument' => $argument];
			});

		$service->defer(jobClass: 'Some\\Job', entry: ['uuid' => 'a']);

		// Still only buffered: nothing reached the job list yet.
		$this->assertSame([], $enqueued);
		$this->assertCount(1, ShutdownFunctionSpy::callbacks());

		ShutdownFunctionSpy::runAll();

		$this->assertCount(1, $enqueued);
		$this->assertSame('Some\\Job', $enqueued[0]['jobClass']);
		$this->assertSame('alice', $enqueued[0]['argument']['userId']);
		$this->assertSame([['uuid' => 'a']], $enqueued[0]['argument']['entries']);
	}

	/**
	 * No entry, no registration: an idle request must not pay for a shutdown
	 * callback it has nothing to flush.
	 */
	public function testNoShutdownCallbackIsRegisteredWithoutADeferredEntry(): void {
		$this->makeService();

		$this->assertSame([], ShutdownFunctionSpy::callbacks());
	}

	public function testEnqueueFailureIsLoggedNotThrown(): void {
		$this->actAs('alice', null);
		$service = $this->makeService();

		$this->jobList->method('add')->willThrowException(new \RuntimeException('joblist down'));
		$this->logger->expects($this->once())->method('error');

		$service->defer(jobClass: 'Some\\Job', entry: ['uuid' => 'a']);
		$service->flushAll();

		// Buffer was reset before the enqueue attempt: no retry storm.
		$service->flushAll();
	}
}
