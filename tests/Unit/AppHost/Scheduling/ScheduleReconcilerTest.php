<?php

/**
 * AppHost scheduling — reconciler upsert/GC/owner/allow-list tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use DateTime;
use OCA\OpenRegister\AppHost\Scheduling\CronScheduleEvaluator;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleActionAllowList;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleDescriptor;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoader;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/TestableReconciler.php';

/**
 * Drives the reconciler with in-memory fixtures (no ObjectService I/O).
 */
class ScheduleReconcilerTest extends TestCase {
	private const NIL_UUID = '00000000-0000-0000-0000-000000000000';
	private const OWNER = 'alice';

	private ScheduleManifestLoader $loader;

	private IUserManager $userManager;

	protected function setUp(): void {
		$this->loader = $this->createMock(ScheduleManifestLoader::class);
		$this->loader->method('loadAllOnDisk')->willReturn([]);

		$user = $this->createMock(IUser::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => ($uid === self::OWNER ? $user : null)
		);
	}

	/**
	 * Build a testable reconciler with overridable I/O and recorded saves.
	 *
	 * @param array<int, array<string, mixed>> $virtual Virtual application fixtures.
	 * @param array<string, array<string, mixed>> $managed Managed-job fixtures keyed by reference.
	 *
	 * @return TestableReconciler
	 */
	private function makeReconciler(array $virtual, array $managed): TestableReconciler {
		return new TestableReconciler(
			$this->createMock(ObjectService::class),
			$this->loader,
			new CronScheduleEvaluator(),
			new ScheduleActionAllowList(),
			$this->userManager,
			$this->createMock(LoggerInterface::class),
			$virtual,
			$managed
		);
	}

	/**
	 * A virtual application row with a manifest carrying the given schedules.
	 *
	 * @param array<int, array<string, mixed>> $schedules Raw schedule entries.
	 * @param string $owner Owner UID (@self.owner).
	 *
	 * @return array<string, mixed>
	 */
	private function app(array $schedules, string $owner = self::OWNER): array {
		return [
			'@self' => ['uuid' => self::NIL_UUID, 'owner' => $owner],
			'manifest' => ['schedules' => $schedules],
		];
	}

	public function testFirstReconciliationCreatesJob(): void {
		$r = $this->makeReconciler(
			[$this->app([['id' => 'nightly', 'interval' => 86400, 'action' => 'openconnector:synchronization']])],
			[]
		);
		$r->reconcile();

		$this->assertCount(1, $r->saved);
		[$data, $uuid] = $r->saved[0];
		$this->assertNull($uuid, 'create → no uuid');
		$this->assertSame(86400, $data['interval']);
		$this->assertTrue($data['isEnabled']);
		$this->assertSame('OCA\\OpenConnector\\Action\\SynchronizationAction', $data['jobClass']);
		$this->assertSame(self::OWNER, $data['userId']);
		$this->assertStringStartsWith('apphost-schedule:', $data['reference']);
	}

	public function testUnchangedScheduleIsNoOp(): void {
		$ref = 'apphost-schedule:' . self::NIL_UUID . ':nightly';
		$existing = [
			'@self' => ['uuid' => 'job-uuid'],
			'reference' => $ref,
			'jobClass' => 'OCA\\OpenConnector\\Action\\SynchronizationAction',
			'arguments' => [],
			'isEnabled' => true,
			'userId' => self::OWNER,
			'name' => 'AppHost schedule nightly',
			'description' => 'Reconciled from manifest schedule "nightly".',
			'interval' => 86400,
		];

		$r = $this->makeReconciler(
			[$this->app([['id' => 'nightly', 'interval' => 86400, 'action' => 'openconnector:synchronization']])],
			[$ref => $existing]
		);
		$r->reconcile();

		$this->assertCount(0, $r->saved);
	}

	public function testChangedFieldUpdatesInPlace(): void {
		$ref = 'apphost-schedule:' . self::NIL_UUID . ':nightly';
		$existing = [
			'@self' => ['uuid' => 'job-uuid'],
			'reference' => $ref,
			'jobClass' => 'OCA\\OpenConnector\\Action\\SynchronizationAction',
			'arguments' => [],
			'isEnabled' => true,
			'userId' => self::OWNER,
			'name' => 'AppHost schedule nightly',
			'description' => 'Reconciled from manifest schedule "nightly".',
			'interval' => 3600,
		];

		$r = $this->makeReconciler(
			[$this->app([['id' => 'nightly', 'interval' => 86400, 'action' => 'openconnector:synchronization']])],
			[$ref => $existing]
		);
		$r->reconcile();

		$this->assertCount(1, $r->saved);
		[$data, $uuid] = $r->saved[0];
		$this->assertSame('job-uuid', $uuid, 'update targets the same job uuid');
		$this->assertSame(86400, $data['interval']);
	}

	public function testDisabledScheduleDisablesJob(): void {
		$ref = 'apphost-schedule:' . self::NIL_UUID . ':nightly';
		$existing = [
			'@self' => ['uuid' => 'job-uuid'],
			'reference' => $ref,
			'jobClass' => 'OCA\\OpenConnector\\Action\\SynchronizationAction',
			'arguments' => [],
			'isEnabled' => true,
			'userId' => self::OWNER,
			'name' => 'AppHost schedule nightly',
			'description' => 'Reconciled from manifest schedule "nightly".',
			'interval' => 86400,
		];

		$r = $this->makeReconciler(
			[$this->app([['id' => 'nightly', 'interval' => 86400, 'action' => 'openconnector:synchronization', 'enabled' => false]])],
			[$ref => $existing]
		);
		$r->reconcile();

		$this->assertCount(1, $r->saved);
		[$data] = $r->saved[0];
		$this->assertFalse($data['isEnabled']);
	}

	public function testRemovedScheduleIsGarbageCollected(): void {
		$ref = 'apphost-schedule:' . self::NIL_UUID . ':gone';
		$existing = [
			'@self' => ['uuid' => 'job-uuid'],
			'reference' => $ref,
			'isEnabled' => true,
			'interval' => 86400,
		];

		// Manifest no longer declares "gone".
		$r = $this->makeReconciler(
			[$this->app([['id' => 'stays', 'interval' => 60, 'action' => 'openconnector:synchronization']])],
			[$ref => $existing]
		);
		$r->reconcile();

		// One save disables the orphan; the "stays" schedule is created too.
		$disabled = array_values(array_filter($r->saved, static fn (array $s): bool => ($s[0]['reference'] ?? '') === $ref));
		$this->assertCount(1, $disabled);
		$this->assertFalse($disabled[0][0]['isEnabled']);
		$this->assertSame('job-uuid', $disabled[0][1]);
	}

	public function testNonAllowListedActionCreatesNoJob(): void {
		$r = $this->makeReconciler(
			[$this->app([['id' => 'evil', 'interval' => 60, 'action' => 'OCA\\Evil\\Backdoor']])],
			[]
		);
		$r->reconcile();

		$this->assertCount(0, $r->saved);
	}

	public function testUnresolvedOwnerSkipsAndCreatesNoJob(): void {
		$r = $this->makeReconciler(
			[$this->app([['id' => 'nightly', 'interval' => 60, 'action' => 'openconnector:synchronization']], 'ghost')],
			[]
		);
		$r->reconcile();

		$this->assertCount(0, $r->saved);
	}

	public function testAuthorRunAsIgnoredOwnerFromApplication(): void {
		$r = $this->makeReconciler(
			[$this->app([[
				'id' => 'nightly',
				'interval' => 60,
				'action' => 'openconnector:synchronization',
				'runAs' => 'ghost',
				'owner' => 'ghost',
			]])],
			[]
		);
		$r->reconcile();

		$this->assertCount(1, $r->saved);
		[$data] = $r->saved[0];
		$this->assertSame(self::OWNER, $data['userId'], 'userId comes from the application owner, not the manifest');
	}

	public function testCronRollForwardKeepsFutureNextRun(): void {
		$r = $this->makeReconciler([], []);
		$now = new DateTime('2026-01-01 00:00:00');
		$desc = new ScheduleDescriptor('c', null, '0 6 * * *', 'openconnector:synchronization');

		$future = (clone $now)->modify('+3 hours')->format(DATE_ATOM);
		$existing = ['@self' => ['uuid' => 'u'], 'nextRun' => $future];

		$data = $r->buildJobData($desc, 'ref', 'JC', self::OWNER, $existing, $now);
		$this->assertSame($future, $data['nextRun'], 'future nextRun is not rewound');
	}

	public function testCronDueRollsForward(): void {
		$r = $this->makeReconciler([], []);
		$now = new DateTime('2026-01-01 12:00:00');
		$desc = new ScheduleDescriptor('c', null, '0 6 * * *', 'openconnector:synchronization');

		$past = (clone $now)->modify('-1 hour')->format(DATE_ATOM);
		$existing = ['@self' => ['uuid' => 'u'], 'nextRun' => $past];

		$data = $r->buildJobData($desc, 'ref', 'JC', self::OWNER, $existing, $now);
		$this->assertNotSame($past, $data['nextRun']);
		$this->assertGreaterThan($now, new DateTime($data['nextRun']));
	}
}//end class
