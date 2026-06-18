<?php

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\WebPushDispatchJob;
use OCA\OpenRegister\Service\WebPush\WebPushService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the out-of-band Web Push dispatch job: run() is invokable, delegates
 * to WebPushService::deliver() with a built payload, skips on a missing uid,
 * and swallows delivery failures (best-effort, never escalates).
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */
class WebPushDispatchJobTest extends TestCase
{
    private WebPushService&MockObject $webPushService;
    private LoggerInterface&MockObject $logger;
    private WebPushDispatchJob $job;

    protected function setUp(): void
    {
        parent::setUp();
        $timeFactory          = $this->createMock(ITimeFactory::class);
        $this->webPushService = $this->createMock(WebPushService::class);
        $this->logger         = $this->createMock(LoggerInterface::class);

        $this->job = new WebPushDispatchJob(
            $timeFactory,
            $this->webPushService,
            $this->logger
        );
    }

    /**
     * Invoke the protected run() method with the given argument.
     *
     * @param array<string, mixed> $argument The job argument.
     *
     * @return void
     */
    private function runJob(array $argument): void
    {
        $reflection = new \ReflectionClass($this->job);
        $method     = $reflection->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($this->job, $argument);
    }

    public function testDelegatesToWebPushServiceWithRecipientUid(): void
    {
        $this->webPushService->expects($this->once())
            ->method('deliver')
            ->with(
                'alice',
                $this->callback(static function (array $payload): bool {
                    return ($payload['title'] ?? null) === 'New lead'
                        && ($payload['body'] ?? null) === 'Acme Corp'
                        && ($payload['tag'] ?? null) === 'lead-1';
                })
            )
            ->willReturn(1);

        $this->runJob([
            'uid'       => 'alice',
            'originApp' => 'pipelinq',
            'title'     => 'New lead',
            'body'      => 'Acme Corp',
            'tag'       => 'lead-1',
        ]);
    }

    public function testSkipsWhenUidMissing(): void
    {
        $this->webPushService->expects($this->never())->method('deliver');
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->runJob(['title' => 'No recipient']);
    }

    public function testSwallowsDeliveryFailure(): void
    {
        $this->webPushService->method('deliver')
            ->willThrowException(new \RuntimeException('push service down'));
        // Best-effort: the job logs a warning and never re-throws.
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->runJob(['uid' => 'bob', 'title' => 'Hi']);
        $this->addToAssertionCount(1);
    }

    public function testBuildsActionPayloadFromDeclaredActions(): void
    {
        $captured = null;
        $this->webPushService->method('deliver')
            ->willReturnCallback(static function (string $uid, array $payload) use (&$captured): int {
                $captured = $payload;
                return 1;
            });

        $this->runJob([
            'uid'     => 'carol',
            'title'   => 'Updated',
            'actions' => [
                [
                    'label'   => ['en' => 'Open client'],
                    'primary' => true,
                    'url'     => 'https://example-host.test/apps/pipelinq/#/clients/1',
                ],
            ],
        ]);

        $this->assertIsArray($captured);
        $this->assertCount(1, $captured['actions']);
        $this->assertSame('Open client', $captured['actions'][0]['title']);
        $this->assertSame('action-0', $captured['actions'][0]['action']);
        // Click-routing URLs live under data, matching the Service Worker contract
        // (js/openregister-push-sw.js reads data.actions[event.action] + data.url).
        $this->assertSame('https://example-host.test/apps/pipelinq/#/clients/1', $captured['data']['actions']['action-0']);
        // The primary action drives the top-level body-click deeplink.
        $this->assertSame('https://example-host.test/apps/pipelinq/#/clients/1', $captured['data']['url']);
    }
}
