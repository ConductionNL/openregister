<?php

/**
 * ObjectCleanupJobTest
 *
 * Unit tests for the deferred half of the deleted-object relation cleanup.
 *
 * Every test asserts an observable side effect — which UUID reaches
 * ObjectRelationCleanupService, and which identity the session carries at the
 * exact moment that cleanup runs. A job that throws nothing while cleaning
 * nothing, or that cleans up under the wrong (or no) actor, fails here.
 *
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

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ObjectCleanupJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\ObjectRelationCleanupService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deferred cleanup: the right UUIDs, under the forwarded actor, never on a
 * UUID that came back to life.
 */
class ObjectCleanupJobTest extends TestCase
{

    /**
     * Stateful session double: remembers the last impersonated user.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Resolver for the captured actor id.
     *
     * @var IUserManager&MockObject
     */
    private IUserManager&MockObject $userManager;

    /**
     * Re-create guard lookup.
     *
     * @var DeferredEntryObjectResolver&MockObject
     */
    private DeferredEntryObjectResolver&MockObject $resolver;

    /**
     * The collaborator that performs the actual deletions.
     *
     * @var ObjectRelationCleanupService&MockObject
     */
    private ObjectRelationCleanupService&MockObject $cleanup;

    /**
     * PSR logger double.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * System under test.
     *
     * @var ObjectCleanupJob
     */
    private ObjectCleanupJob $job;

    /**
     * The user currently "logged in" on the session double.
     *
     * @var IUser|null
     */
    private ?IUser $sessionUser = null;

    /**
     * Every value handed to IUserSession::setUser(), in order.
     *
     * @var array<int, IUser|null>
     */
    private array $setUserCalls = [];

    /**
     * Build the job with a session double that actually holds a user.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->userSession = $this->createMock(originalClassName: IUserSession::class);
        $this->userManager = $this->createMock(originalClassName: IUserManager::class);
        $this->resolver    = $this->createMock(originalClassName: DeferredEntryObjectResolver::class);
        $this->cleanup     = $this->createMock(originalClassName: ObjectRelationCleanupService::class);
        $this->logger      = $this->createMock(originalClassName: LoggerInterface::class);

        $this->sessionUser  = null;
        $this->setUserCalls = [];

        // A plain return-value stub cannot show WHO the cleanup ran as, so the
        // session double is stateful: setUser() stores, getUser() reads back.
        $this->userSession->method('getUser')->willReturnCallback(
            function (): ?IUser {
                return $this->sessionUser;
            }
        );
        $this->userSession->method('setUser')->willReturnCallback(
            function ($user): void {
                $this->sessionUser    = $user;
                $this->setUserCalls[] = $user;
            }
        );

        $this->job = new ObjectCleanupJob(
            time: $this->createMock(originalClassName: ITimeFactory::class),
            userSession: $this->userSession,
            userManager: $this->userManager,
            organisation: $this->createMock(originalClassName: OrganisationService::class),
            logger: $this->logger,
            resolver: $this->resolver,
            cleanup: $this->cleanup
        );

    }//end setUp()

    /**
     * Make a user id resolvable by the job's IUserManager.
     *
     * @param string $userId The user id to resolve.
     *
     * @return IUser&MockObject The user the job will impersonate.
     */
    private function resolvableUser(string $userId): IUser&MockObject
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn($userId);
        $this->userManager->method('get')->with($userId)->willReturn($user);

        return $user;

    }//end resolvableUser()

    /**
     * Invoke the protected QueuedJob::run() with a serialized context.
     *
     * @param array<string, mixed> $argument The job argument payload.
     *
     * @return void
     */
    private function invokeRun(array $argument): void
    {
        // No setAccessible() call: protected members have been reflection
        // invocable without it since PHP 8.1, and the method is deprecated.
        $method = (new \ReflectionClass(objectOrClass: $this->job))->getMethod('run');
        $method->invoke($this->job, $argument);

    }//end invokeRun()

    /**
     * Run the job over the given entries as the given actor.
     *
     * @param array<int, array<string, mixed>> $entries The deferred entries.
     * @param string|null                      $userId  The captured actor id.
     *
     * @return void
     */
    private function runJob(array $entries, ?string $userId='alice'): void
    {
        $argument = (new DeferredListenerContext(
            userId: $userId,
            orgUuid: null,
            entries: $entries
        ))->toJobArguments();

        $this->invokeRun(argument: $argument);

    }//end runJob()

    /**
     * POSITIVE CONTROL — every gone object is cleaned up, as the actor.
     *
     * Asserts both halves at once: the UUIDs that reach the cleanup service,
     * and the session identity in force while each call happens.
     *
     * @return void
     */
    public function testCleansUpEveryGoneEntryUnderTheForwardedActor(): void
    {
        $alice = $this->resolvableUser(userId: 'alice');
        $this->resolver->method('resolve')->willReturn(null);

        $cleaned = [];
        $this->cleanup->expects($this->exactly(count: 2))->method('cleanup')
            ->willReturnCallback(
                function (string $objectUuid) use (&$cleaned): void {
                    $cleaned[] = [$objectUuid, $this->userSession->getUser()?->getUID()];
                }
            );

        $this->runJob(
            entries: [
                ['uuid' => 'gone-a', 'register' => 'r', 'schema' => 's'],
                ['uuid' => 'gone-b', 'register' => 'r', 'schema' => 's'],
            ]
        );

        // The cleanup really ran, on the right UUIDs, as the captured actor.
        $this->assertSame(
            expected: [['gone-a', 'alice'], ['gone-b', 'alice']],
            actual: $cleaned
        );

        // And the borrowed identity was handed back afterwards.
        $this->assertSame(expected: [$alice, null], actual: $this->setUserCalls);

    }//end testCleansUpEveryGoneEntryUnderTheForwardedActor()

    /**
     * The actor captured at defer time is the actor the job cleans up as.
     *
     * Drives the real ListenerDeferralService to build the job argument, so
     * the assertion covers the whole capture -> serialize -> restore seam
     * rather than a hand-written payload.
     *
     * @return void
     */
    public function testActorCapturedAtDeferTimeIsTheActorTheJobRunsAs(): void
    {
        $deferringSession = $this->createMock(originalClassName: IUserSession::class);
        $deferringUser    = $this->createMock(originalClassName: IUser::class);
        $deferringUser->method('getUID')->willReturn('bob');
        $deferringSession->method('getUser')->willReturn($deferringUser);

        $argument = null;
        $jobList  = $this->createMock(originalClassName: IJobList::class);
        $jobList->expects($this->once())->method('add')
            ->willReturnCallback(
                function (string $jobClass, $jobArgument) use (&$argument): void {
                    $argument = $jobArgument;
                }
            );

        $deferral = new ListenerDeferralService(
            userSession: $deferringSession,
            organisation: $this->createMock(originalClassName: OrganisationService::class),
            jobList: $jobList,
            appConfig: $this->createMock(originalClassName: IAppConfig::class),
            logger: $this->createMock(originalClassName: LoggerInterface::class)
        );

        $deferral->defer(
            jobClass: ObjectCleanupJob::class,
            entry: ['uuid' => 'gone', 'register' => 'r', 'schema' => 's'],
            dedupeKey: 'gone'
        );
        $deferral->flushAll();

        $this->assertSame(expected: 'bob', actual: $argument['userId']);

        // Only 'bob' resolves — asking for anything else yields no user and
        // ActorForwardedJob would skip the work entirely.
        $bob = $this->createMock(originalClassName: IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $this->userManager->expects($this->once())->method('get')->with('bob')->willReturn($bob);
        $this->resolver->method('resolve')->willReturn(null);

        $actorDuringCleanup = null;
        $this->cleanup->expects($this->once())->method('cleanup')->with('gone')
            ->willReturnCallback(
                function () use (&$actorDuringCleanup): void {
                    $actorDuringCleanup = $this->userSession->getUser()?->getUID();
                }
            );

        $this->invokeRun(argument: $argument);

        $this->assertSame(expected: 'bob', actual: $actorDuringCleanup);

    }//end testActorCapturedAtDeferTimeIsTheActorTheJobRunsAs()

    /**
     * The whole entry — not just the uuid — reaches the re-create guard.
     *
     * Register and schema must survive the job-argument round trip, otherwise
     * the guard degrades to a cross-table UUID scan.
     *
     * @return void
     */
    public function testResolverReceivesTheFullEntryIdentifiers(): void
    {
        $this->resolvableUser(userId: 'alice');

        $seen = [];
        $this->resolver->expects($this->once())->method('resolve')
            ->willReturnCallback(
                static function (array $entry) use (&$seen): ?ObjectEntity {
                    $seen = $entry;
                    return null;
                }
            );
        $this->cleanup->expects($this->once())->method('cleanup')->with('gone-a');

        $this->runJob(entries: [['uuid' => 'gone-a', 'register' => 'reg-1', 'schema' => 'sch-1']]);

        $this->assertSame(
            expected: ['uuid' => 'gone-a', 'register' => 'reg-1', 'schema' => 'sch-1'],
            actual: $seen
        );

    }//end testResolverReceivesTheFullEntryIdentifiers()

    /**
     * NEGATIVE CONTROL — a UUID that came back to life is never cleaned.
     *
     * Paired with testCleansUpEveryGoneEntryUnderTheForwardedActor(), which
     * proves the same collaborator IS called in the ordinary case.
     *
     * @return void
     */
    public function testUuidThatResolvesToALiveObjectIsNotCleanedUp(): void
    {
        $this->resolvableUser(userId: 'alice');

        $live = new ObjectEntity();
        $live->setUuid('reborn');
        $this->resolver->method('resolve')->willReturn($live);

        $this->cleanup->expects($this->never())->method('cleanup');
        $this->logger->expects($this->once())->method('info')
            ->with($this->stringContains(string: 'live object'), $this->anything());

        $this->runJob(entries: [['uuid' => 'reborn', 'register' => 'r', 'schema' => 's']]);

    }//end testUuidThatResolvesToALiveObjectIsNotCleanedUp()

    /**
     * A mixed chunk cleans exactly the entry whose object is gone.
     *
     * Positive and negative control inside one run: dead code cleaning
     * nothing fails the assertion, an unguarded loop cleaning everything
     * fails the once() expectation.
     *
     * @return void
     */
    public function testMixedChunkCleansOnlyTheEntryThatIsReallyGone(): void
    {
        $this->resolvableUser(userId: 'alice');

        $live = new ObjectEntity();
        $live->setUuid('reborn');
        $this->resolver->method('resolve')->willReturnCallback(
            static function (array $entry) use ($live): ?ObjectEntity {
                if ($entry['uuid'] === 'reborn') {
                    return $live;
                }

                return null;
            }
        );

        $cleaned = [];
        $this->cleanup->expects($this->once())->method('cleanup')
            ->willReturnCallback(
                static function (string $objectUuid) use (&$cleaned): void {
                    $cleaned[] = $objectUuid;
                }
            );

        $this->runJob(
            entries: [
                ['uuid' => 'reborn', 'register' => 'r', 'schema' => 's'],
                ['uuid' => 'gone', 'register' => 'r', 'schema' => 's'],
            ]
        );

        $this->assertSame(expected: ['gone'], actual: $cleaned);

    }//end testMixedChunkCleansOnlyTheEntryThatIsReallyGone()

    /**
     * Entries without a usable uuid are skipped, the rest still runs.
     *
     * @return void
     */
    public function testEntryWithoutAUsableUuidIsSkippedWithoutStoppingTheChunk(): void
    {
        $this->resolvableUser(userId: 'alice');

        $this->resolver->expects($this->once())->method('resolve')->willReturn(null);
        $this->cleanup->expects($this->once())->method('cleanup')->with('gone');

        $this->runJob(
            entries: [
                ['register' => 'r', 'schema' => 's'],
                ['uuid' => '', 'register' => 'r', 'schema' => 's'],
                ['uuid' => 42, 'register' => 'r', 'schema' => 's'],
                ['uuid' => 'gone', 'register' => 'r', 'schema' => 's'],
            ]
        );

    }//end testEntryWithoutAUsableUuidIsSkippedWithoutStoppingTheChunk()

    /**
     * A failing cleanup is logged and the remaining entries still run.
     *
     * @return void
     */
    public function testCleanupFailureIsLoggedAndTheChunkContinues(): void
    {
        $this->resolvableUser(userId: 'alice');
        $this->resolver->method('resolve')->willReturn(null);

        $cleaned = [];
        $this->cleanup->expects($this->exactly(count: 2))->method('cleanup')
            ->willReturnCallback(
                static function (string $objectUuid) use (&$cleaned): void {
                    if ($objectUuid === 'boom') {
                        throw new \RuntimeException('cleanup blew up');
                    }

                    $cleaned[] = $objectUuid;
                }
            );
        $this->logger->expects($this->once())->method('warning');

        $this->runJob(
            entries: [
                ['uuid' => 'boom', 'register' => 'r', 'schema' => 's'],
                ['uuid' => 'gone', 'register' => 'r', 'schema' => 's'],
            ]
        );

        $this->assertSame(expected: ['gone'], actual: $cleaned);

    }//end testCleanupFailureIsLoggedAndTheChunkContinues()

    /**
     * NEGATIVE CONTROL — an actor that no longer exists cleans up nothing.
     *
     * Running anyway would delete another tenant's notes and tasks under
     * whatever identity the cron worker happens to carry.
     *
     * @return void
     */
    public function testVanishedActorSkipsTheCleanupEntirely(): void
    {
        $this->userManager->method('get')->with('ghost')->willReturn(null);

        $this->resolver->expects($this->never())->method('resolve');
        $this->cleanup->expects($this->never())->method('cleanup');
        $this->logger->expects($this->once())->method('warning');

        $this->runJob(
            entries: [['uuid' => 'gone', 'register' => 'r', 'schema' => 's']],
            userId: 'ghost'
        );

        // No identity was borrowed, so none had to be given back.
        $this->assertSame(expected: [], actual: $this->setUserCalls);

    }//end testVanishedActorSkipsTheCleanupEntirely()

    /**
     * A session-less origin (occ, cron) still cleans up, without impersonating.
     *
     * @return void
     */
    public function testSessionlessOriginCleansUpWithoutImpersonation(): void
    {
        $this->userManager->expects($this->never())->method('get');
        $this->resolver->method('resolve')->willReturn(null);

        $actorDuringCleanup = 'not-called';
        $this->cleanup->expects($this->once())->method('cleanup')->with('gone')
            ->willReturnCallback(
                function () use (&$actorDuringCleanup): void {
                    $actorDuringCleanup = $this->userSession->getUser();
                }
            );

        $this->runJob(
            entries: [['uuid' => 'gone', 'register' => 'r', 'schema' => 's']],
            userId: null
        );

        $this->assertNull(actual: $actorDuringCleanup);
        $this->assertSame(expected: [null], actual: $this->setUserCalls);

    }//end testSessionlessOriginCleansUpWithoutImpersonation()
}//end class
