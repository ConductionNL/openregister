<?php

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\TranslationProjectionJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deferred projection: same effect as inline, under the forwarded actor,
 * stale entries skipped, chunk survives per-entry failures.
 */
class TranslationProjectionJobTest extends TestCase
{
    private IUserSession&MockObject $userSession;
    private IUserManager&MockObject $userManager;
    private DeferredEntryObjectResolver&MockObject $resolver;
    private TranslationProjectionService&MockObject $projection;
    private LoggerInterface&MockObject $logger;
    private TranslationProjectionJob $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->resolver    = $this->createMock(DeferredEntryObjectResolver::class);
        $this->projection  = $this->createMock(TranslationProjectionService::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->job = new TranslationProjectionJob(
            time: $this->createMock(ITimeFactory::class),
            userSession: $this->userSession,
            userManager: $this->userManager,
            organisation: $this->createMock(OrganisationService::class),
            logger: $this->logger,
            resolver: $this->resolver,
            projection: $this->projection,
        );
    }

    private function runJob(array $entries, ?string $userId='alice'): void
    {
        if ($userId !== null) {
            $user = $this->createMock(IUser::class);
            $this->userManager->method('get')->with($userId)->willReturn($user);
        }

        $argument = (new DeferredListenerContext(userId: $userId, orgUuid: null, entries: $entries))
            ->toJobArguments();

        $method = (new \ReflectionClass($this->job))->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($this->job, $argument);
    }

    public function testProjectsEveryLiveEntryUnderTheForwardedActor(): void
    {
        $objectA = new ObjectEntity();
        $objectA->setUuid('a');
        $objectB = new ObjectEntity();
        $objectB->setUuid('b');

        $this->resolver->method('resolve')->willReturnOnConsecutiveCalls($objectA, $objectB);

        // The actor is set on the session BEFORE projection runs, so
        // project()'s translator attribution sees the forwarded user.
        $sessionSetBeforeProject = [];
        $impersonated = false;
        $this->userSession->method('setUser')->willReturnCallback(
            function ($user) use (&$impersonated): void {
                $impersonated = ($user !== null);
            }
        );
        $this->projection->expects($this->exactly(2))->method('project')
            ->willReturnCallback(function (ObjectEntity $object) use (&$sessionSetBeforeProject, &$impersonated): void {
                $sessionSetBeforeProject[] = [$object->getUuid(), $impersonated];
            });

        $this->runJob([
            ['uuid' => 'a', 'register' => 'r', 'schema' => 's'],
            ['uuid' => 'b', 'register' => 'r', 'schema' => 's'],
        ]);

        $this->assertSame([['a', true], ['b', true]], $sessionSetBeforeProject);
    }

    public function testStaleEntriesAreSkipped(): void
    {
        $this->resolver->method('resolve')->willReturn(null);
        $this->projection->expects($this->never())->method('project');

        $this->runJob([['uuid' => 'gone', 'register' => 'r', 'schema' => 's']]);
    }

    public function testPerEntryFailureDoesNotAbortTheChunk(): void
    {
        $objectA = new ObjectEntity();
        $objectA->setUuid('a');
        $objectB = new ObjectEntity();
        $objectB->setUuid('b');
        $this->resolver->method('resolve')->willReturnOnConsecutiveCalls($objectA, $objectB);

        $projected = [];
        $this->projection->expects($this->exactly(2))->method('project')
            ->willReturnCallback(function (ObjectEntity $object) use (&$projected): void {
                if ($object->getUuid() === 'a') {
                    throw new \RuntimeException('projection blew up');
                }

                $projected[] = $object->getUuid();
            });
        $this->logger->expects($this->once())->method('warning');

        $this->runJob([
            ['uuid' => 'a', 'register' => 'r', 'schema' => 's'],
            ['uuid' => 'b', 'register' => 'r', 'schema' => 's'],
        ]);

        $this->assertSame(['b'], $projected);
    }
}
