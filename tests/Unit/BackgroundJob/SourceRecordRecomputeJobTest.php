<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\SourceRecordRecomputeJob;
use OCA\OpenRegister\BackgroundJob\Support\ActorForwardingServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\Merge\MasterRecomputeInterface;
use OCA\OpenRegister\Service\Merge\MasterRecomputeService;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which masters the deferred source-record recompute reaches, and as whom.
 *
 * @spec openspec/changes/object-event-sync-async-split/tasks.md#4.1
 */
class SourceRecordRecomputeJobTest extends TestCase {

	/**
	 * Every entry with a master uuid is recomputed once; an empty or missing uuid is skipped.
	 *
	 * @return void
	 */
	public function testRecomputesEachNamedMasterAndSkipsBlankOnes(): void {
		$recompute = $this->createMock(originalClassName: MasterRecomputeInterface::class);
		$seen = [];
		$recompute->method('recompute')->willReturnCallback(
			function (string $masterUuid) use (&$seen): void {
				$seen[] = $masterUuid;
			}
		);

		$job = new SourceRecordRecomputeJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			actor: new ActorForwardingServices(
				userSession: $this->createMock(originalClassName: IUserSession::class),
				userManager: $this->createMock(originalClassName: IUserManager::class),
				organisation: $this->createMock(originalClassName: OrganisationServiceInterface::class),
				logger: $this->createMock(originalClassName: LoggerInterface::class)
			),
			recompute: $recompute
		);

		$argument = (new DeferredListenerContext(
			userId: null,
			orgUuid: null,
			entries: [['masterUuid' => 'm-1'], ['masterUuid' => ''], ['other' => 'x'], ['masterUuid' => 'm-2']]
		))->toJobArguments();

		$method = (new \ReflectionClass($job))->getMethod('run');
		$method->invoke($job, $argument);

		$this->assertSame(expected: ['m-1', 'm-2'], actual: $seen);
	}//end testRecomputesEachNamedMasterAndSkipsBlankOnes()

	/**
	 * The recompute runs as the actor captured at defer time, and the worker's
	 * own (empty) session is restored afterwards.
	 *
	 * @return void
	 */
	public function testRecomputeRunsAsTheForwardedActorAndRestoresTheSession(): void {
		$alice = $this->createMock(originalClassName: IUser::class);
		$alice->method('getUID')->willReturn('alice');

		$current = null;
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use (&$current): ?IUser {
				return $current;
			}
		);
		$session->method('setUser')->willReturnCallback(
			function (?IUser $user) use (&$current): void {
				$current = $user;
			}
		);

		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->expects($this->once())->method('get')->with('alice')->willReturn($alice);

		$actorDuringRecompute = [];
		$recompute = $this->createMock(originalClassName: MasterRecomputeInterface::class);
		$recompute->expects($this->exactly(count: 2))->method('recompute')->willReturnCallback(
			function (string $masterUuid) use (&$current, &$actorDuringRecompute): void {
				$actorDuringRecompute[$masterUuid] = $current?->getUID();
			}
		);

		$this->runJob(
			job: $this->job(recompute: $recompute, session: $session, users: $users),
			userId: 'alice',
			entries: [['masterUuid' => 'm-1'], ['masterUuid' => 'm-2']]
		);

		$this->assertSame(expected: ['m-1' => 'alice', 'm-2' => 'alice'], actual: $actorDuringRecompute);
		$this->assertNull(actual: $current);
	}//end testRecomputeRunsAsTheForwardedActorAndRestoresTheSession()

	/**
	 * An actor who no longer exists recomputes nothing: the work is not
	 * silently run under the cron worker's identity instead.
	 *
	 * @return void
	 */
	public function testAVanishedActorRecomputesNothing(): void {
		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->with('gone-user')->willReturn(null);

		$recompute = $this->createMock(originalClassName: MasterRecomputeInterface::class);
		$recompute->expects($this->never())->method('recompute');

		$this->runJob(
			job: $this->job(recompute: $recompute, users: $users),
			userId: 'gone-user',
			entries: [['masterUuid' => 'm-1']]
		);
	}//end testAVanishedActorRecomputesNothing()

	/**
	 * A master that no longer resolves is a stale no-op and does not stop the
	 * chunk: only the master that still exists is re-saved.
	 *
	 * @return void
	 */
	public function testAStaleMasterIsANoOpAndTheNextMasterIsStillRecomputed(): void {
		$live = new ObjectEntity();
		$live->setUuid('m-live');
		$live->setRegister('7');
		$live->setSchema('9');
		$live->setObject(['name' => 'Golden']);

		$objects = $this->createMock(originalClassName: ObjectOperationsInterface::class);
		$objects->expects($this->exactly(count: 2))->method('find')->willReturnCallback(
			static function (int|string $id) use ($live): ?ObjectEntity {
				if ($id === 'm-live') {
					return $live;
				}

				return null;
			}
		);

		$saved = [];
		$objects->expects($this->once())->method('saveObject')->willReturnCallback(
			static function (array|ObjectEntity $object) use (&$saved): ObjectEntity {
				$saved[] = $object->getUuid();
				return $object;
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->runJob(
			job: $this->job(recompute: new MasterRecomputeService(objectService: $objects, logger: $logger)),
			userId: null,
			entries: [['masterUuid' => 'm-gone'], ['masterUuid' => 'm-live']]
		);

		$this->assertSame(expected: ['m-live'], actual: $saved);
	}//end testAStaleMasterIsANoOpAndTheNextMasterIsStillRecomputed()

	/**
	 * Build the job over the given collaborators.
	 *
	 * @param MasterRecomputeInterface $recompute The recompute collaborator.
	 * @param IUserSession|null        $session   The session, a bare mock when null.
	 * @param IUserManager|null        $users     The user manager, a bare mock when null.
	 *
	 * @return SourceRecordRecomputeJob
	 */
	private function job(
		MasterRecomputeInterface $recompute,
		?IUserSession $session=null,
		?IUserManager $users=null
	): SourceRecordRecomputeJob {
		return new SourceRecordRecomputeJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			actor: new ActorForwardingServices(
				userSession: $session ?? $this->createMock(originalClassName: IUserSession::class),
				userManager: $users ?? $this->createMock(originalClassName: IUserManager::class),
				organisation: $this->createMock(originalClassName: OrganisationServiceInterface::class),
				logger: $this->createMock(originalClassName: LoggerInterface::class)
			),
			recompute: $recompute
		);
	}//end job()

	/**
	 * Run the job the way the job list does, with a captured context.
	 *
	 * @param SourceRecordRecomputeJob         $job     The job.
	 * @param string|null                      $userId  The captured actor.
	 * @param array<int, array<string, mixed>> $entries The buffered entries.
	 *
	 * @return void
	 */
	private function runJob(SourceRecordRecomputeJob $job, ?string $userId, array $entries): void {
		$argument = (new DeferredListenerContext(userId: $userId, orgUuid: null, entries: $entries))->toJobArguments();
		(new \ReflectionClass($job))->getMethod('run')->invoke($job, $argument);
	}//end runJob()
}//end class
