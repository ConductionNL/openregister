<?php

declare(strict_types=1);

namespace Unit\Service\WebPush;

use OCA\OpenRegister\Db\PushSubscriptionMapper;
use OCA\OpenRegister\Service\WebPush\WebPushService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the configuration gating and delivery short-circuits of WebPushService.
 *
 * The encrypt/sign/POST/prune path runs through a real Minishlink\WebPush\WebPush
 * built inside the private buildWebPush() (no injectable seam), so the actual
 * push-service POST + 404/410 prune is exercised at integration level. Here we
 * cover everything reachable without a live HTTP round-trip: the configured
 * gate, the public-key accessor, the no-keypair no-op, and the no-subscriptions
 * no-op — all of which assert deliver() never queues when it must not.
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */
class WebPushServiceTest extends TestCase
{
    private PushSubscriptionMapper&MockObject $mapper;
    private IAppConfig&MockObject $appConfig;
    private IURLGenerator&MockObject $urlGenerator;
    private LoggerInterface&MockObject $logger;
    private WebPushService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper       = $this->createMock(PushSubscriptionMapper::class);
        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->service = new WebPushService(
            $this->mapper,
            $this->appConfig,
            $this->urlGenerator,
            $this->logger
        );
    }

    /**
     * Wire the IAppConfig mock to return the given VAPID keypair.
     *
     * @param string $public  Public key value.
     * @param string $private Private key value.
     *
     * @return void
     */
    private function withKeypair(string $public, string $private): void
    {
        $this->appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = '') use ($public, $private): string {
                if ($key === WebPushService::VAPID_PUBLIC_KEY) {
                    return $public;
                }
                if ($key === WebPushService::VAPID_PRIVATE_KEY) {
                    return $private;
                }
                return $default;
            }
        );
    }

    public function testIsConfiguredTrueWhenBothKeysPresent(): void
    {
        $this->withKeypair('<VAPID_PUBLIC_KEY>', '<VAPID_PRIVATE_KEY>');
        $this->assertTrue($this->service->isConfigured());
    }

    public function testIsConfiguredFalseWhenPrivateKeyMissing(): void
    {
        $this->withKeypair('<VAPID_PUBLIC_KEY>', '');
        $this->assertFalse($this->service->isConfigured());
    }

    public function testGetPublicKeyReturnsConfiguredKeyAndNeverPrivate(): void
    {
        $this->withKeypair('<VAPID_PUBLIC_KEY>', '<VAPID_PRIVATE_KEY>');
        $this->assertSame('<VAPID_PUBLIC_KEY>', $this->service->getPublicKey());
    }

    public function testDeliverIsNoOpWhenNotConfigured(): void
    {
        $this->withKeypair('', '');
        // Must short-circuit before ever looking up subscriptions.
        $this->mapper->expects($this->never())->method('findByUser');
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $delivered = $this->service->deliver('alice', ['title' => 'Hi']);
        $this->assertSame(0, $delivered);
    }

    public function testDeliverIsNoOpWhenNoSubscriptions(): void
    {
        $this->withKeypair('<VAPID_PUBLIC_KEY>', '<VAPID_PRIVATE_KEY>');
        $this->mapper->expects($this->once())
            ->method('findByUser')
            ->with('alice')
            ->willReturn([]);
        // No subscriptions → nothing to prune, nothing delivered.
        $this->mapper->expects($this->never())->method('deleteByEndpoint');

        $delivered = $this->service->deliver('alice', ['title' => 'Hi']);
        $this->assertSame(0, $delivered);
    }

    public function testDeliverLooksUpRecipientSubscriptionsWhenConfigured(): void
    {
        // The encrypt/sign/POST + 404/410 prune happens through a real
        // Minishlink WebPush built inside the private buildWebPush() — there is
        // no injectable seam, and feeding a live endpoint would make this unit
        // test depend on an outbound HTTP round-trip. So the prune-on-410 path
        // is asserted at integration level (see the change's acceptance
        // criteria). Here we pin the reachable contract: a configured service
        // looks the recipient's subscriptions up by uid, and the empty-result
        // branch returns 0 without ever pruning.
        $this->withKeypair('<VAPID_PUBLIC_KEY>', '<VAPID_PRIVATE_KEY>');

        $this->mapper->expects($this->once())
            ->method('findByUser')
            ->with('bob')
            ->willReturn([]);
        $this->mapper->expects($this->never())->method('deleteByEndpoint');

        $delivered = $this->service->deliver('bob', ['title' => 'Hi', 'body' => 'There']);
        $this->assertSame(0, $delivered);
    }
}
