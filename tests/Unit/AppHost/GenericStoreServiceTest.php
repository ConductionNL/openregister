<?php

/**
 * Tests for the AppHost GenericStoreService (ADR-080 store plane).
 *
 * Ported from openbuild's RemoteTemplateStoreServiceTest as part of moving the
 * store client into OpenRegister. Per ADR-080 Consequences the SSRF negative
 * controls (private address rejected, non-http(s) scheme rejected, redirect
 * never followed) are NOT optional in this migration — they are the reason the
 * code moved, so they are asserted here directly rather than assumed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for GenericStoreService.
 */
class GenericStoreServiceTest extends TestCase
{
    /**
     * Mock HTTP client factory.
     *
     * @var IClientService&MockObject
     */
    private IClientService&MockObject $clientService;

    /**
     * Mock app config.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clientService = $this->createMock(IClientService::class);
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

    }//end setUp()

    /**
     * Build the service under test.
     *
     * @return GenericStoreService
     */
    private function service(): GenericStoreService
    {
        return new GenericStoreService(
            clientService: $this->clientService,
            appConfig: $this->appConfig,
            logger: $this->logger
        );

    }//end service()

    /**
     * A descriptor standing in for a leaf app's store.
     *
     * @param string $appId  The leaf app id.
     * @param string $schema The remote schema slug.
     *
     * @return StoreDescriptor
     */
    private function descriptor(string $appId='openbuild', string $schema='application-template'): StoreDescriptor
    {
        return new StoreDescriptor(
            appId: $appId,
            schema: $schema,
            defaultRegister: $appId
        );

    }//end descriptor()

    /**
     * Wire IAppConfig::getValueString to return the given registry config.
     *
     * @param string $url      The registry base URL.
     * @param string $register The register segment.
     * @param string $token    The optional read token.
     *
     * @return void
     */
    private function configure(string $url, string $register='openbuild', string $token=''): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(
                static function (string $app, string $key, string $default='') use ($url, $register, $token): string {
                    return match ($key) {
                        'registry_url'      => $url,
                        'registry_register' => $register,
                        'registry_token'    => $token,
                        default             => $default,
                    };
                }
            );

    }//end configure()

    /**
     * Stub the HTTP client to return the given body, capturing request options.
     *
     * @param string                    $body     The response body.
     * @param int                       $status   The HTTP status code.
     * @param array<string, mixed>|null &$options Receives the captured request options.
     * @param string|null               &$url     Receives the captured request URL.
     *
     * @return void
     */
    private function stubResponse(string $body, int $status=200, ?array &$options=null, ?string &$url=null): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturnCallback(
            static function (string $u, array $o) use ($response, &$options, &$url): IResponse {
                $url     = $u;
                $options = $o;
                return $response;
            }
        );

        $this->clientService->method('newClient')->willReturn($client);

    }//end stubResponse()

    /**
     * No registry configured means no network call at all — the ADR-080
     * Decision 4 fallback that lets a store page render built-in items.
     *
     * @return void
     */
    public function testUnconfiguredStoreMakesNoNetworkCall(): void
    {
        $this->configure(url: '');
        $this->clientService->expects(self::never())->method('newClient');

        $result = $this->service()->search(descriptor: $this->descriptor());

        self::assertSame(GenericStoreService::OUTCOME_NOT_CONFIGURED, $result['outcome']);
        self::assertSame([], $result['cards']);

    }//end testUnconfiguredStoreMakesNoNetworkCall()

    /**
     * A configured store returns normalised cards.
     *
     * @return void
     */
    public function testSearchReturnsNormalisedCards(): void
    {
        $this->configure(url: 'https://93.184.216.34/');
        $this->stubResponse(
            body: json_encode(
                [
                    'results' => [
                        [
                            'slug'        => 'crm',
                            'title'       => 'CRM',
                            'description' => 'A CRM app',
                            'category'    => 'sales',
                            'version'     => '1.0.0',
                            'kind'        => 'app-template',
                        ],
                    ],
                ]
            )
        );

        $result = $this->service()->search(descriptor: $this->descriptor());

        self::assertSame(GenericStoreService::OUTCOME_OK, $result['outcome']);
        self::assertCount(1, $result['cards']);
        self::assertSame('crm', $result['cards'][0]['slug']);
        self::assertSame('app-template', $result['cards'][0]['kind']);

    }//end testSearchReturnsNormalisedCards()

    /**
     * A card never carries the install payload — only the descriptor's fields
     * plus `kind`. Guards against leaking a manifest (or worse) into a list.
     *
     * @return void
     */
    public function testCardOmitsFieldsOutsideTheDescriptor(): void
    {
        $this->configure(url: 'https://93.184.216.34/');
        $this->stubResponse(
            body: json_encode(
                [
                    'results' => [
                        [
                            'slug'     => 'crm',
                            'title'    => 'CRM',
                            'manifest' => ['pages' => ['secret']],
                            'token'    => 'should-never-appear',
                        ],
                    ],
                ]
            )
        );

        $card = $this->service()->search(descriptor: $this->descriptor())['cards'][0];

        self::assertArrayNotHasKey('manifest', $card);
        self::assertArrayNotHasKey('token', $card);

    }//end testCardOmitsFieldsOutsideTheDescriptor()

    /**
     * SSRF negative control: a private-address registry is rejected before any
     * request is issued.
     *
     * @return void
     */
    public function testPrivateAddressRegistryIsRejected(): void
    {
        $this->configure(url: 'http://192.168.1.10/');
        $this->clientService->expects(self::never())->method('newClient');

        $result = $this->service()->search(descriptor: $this->descriptor());

        self::assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $result['outcome']);

    }//end testPrivateAddressRegistryIsRejected()

    /**
     * SSRF negative control: a non-http(s) scheme is rejected fail-closed.
     *
     * @return void
     */
    public function testNonHttpSchemeRegistryIsRejected(): void
    {
        $this->configure(url: 'file:///etc/passwd');
        $this->clientService->expects(self::never())->method('newClient');

        $result = $this->service()->search(descriptor: $this->descriptor());

        self::assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $result['outcome']);

    }//end testNonHttpSchemeRegistryIsRejected()

    /**
     * SSRF behaviour worth pinning: the guard resolves DNS and fails CLOSED,
     * so an unresolvable registry host is rejected without a network call.
     *
     * This is why the positive-path tests above use a literal public IP rather
     * than a hostname — a made-up hostname does not resolve in CI, so every
     * case would return `store_unreachable` and the negative controls would
     * pass for the wrong reason (proving only that everything is rejected).
     *
     * @return void
     */
    public function testUnresolvableRegistryHostIsRejectedFailClosed(): void
    {
        $this->configure(url: 'https://registry.invalid/');
        $this->clientService->expects(self::never())->method('newClient');

        $result = $this->service()->search(descriptor: $this->descriptor());

        self::assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $result['outcome']);

    }//end testUnresolvableRegistryHostIsRejectedFailClosed()

    /**
     * SSRF negative control: redirects are never followed, so a public host
     * cannot bounce the Bearer token at a private/metadata address.
     *
     * @return void
     */
    public function testRegistryFetchNeverFollowsRedirects(): void
    {
        $this->configure(url: 'https://93.184.216.34/', token: 'secret-token');
        $captured = null;
        $this->stubResponse(body: json_encode(['results' => []]), options: $captured);

        $this->service()->search(descriptor: $this->descriptor());

        self::assertIsArray($captured);
        self::assertArrayHasKey('allow_redirects', $captured);
        self::assertFalse($captured['allow_redirects'], 'registry fetch must not follow redirects');

    }//end testRegistryFetchNeverFollowsRedirects()

    /**
     * The token travels as a Bearer header and nowhere else.
     *
     * @return void
     */
    public function testTokenIsSentOnlyAsABearerHeader(): void
    {
        $this->configure(url: 'https://93.184.216.34/', token: 'secret-token');
        $captured = null;
        $url      = null;
        $this->stubResponse(body: json_encode(['results' => []]), options: $captured, url: $url);

        $this->service()->search(descriptor: $this->descriptor());

        self::assertSame('Bearer secret-token', $captured['headers']['Authorization']);
        self::assertStringNotContainsString('secret-token', (string) $url);
        self::assertStringNotContainsString('secret-token', json_encode($captured['query']));

    }//end testTokenIsSentOnlyAsABearerHeader()

    /**
     * An unreachable registry yields a generic outcome, not an upstream error.
     *
     * @return void
     */
    public function testUnreachableRegistryYieldsGenericOutcome(): void
    {
        $this->configure(url: 'https://93.184.216.34/');
        $client = $this->createMock(IClient::class);
        $client->method('get')->willThrowException(new RuntimeException('connect timeout to 10.0.0.5'));
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->service()->search(descriptor: $this->descriptor());

        self::assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $result['outcome']);
        self::assertSame([], $result['cards']);

    }//end testUnreachableRegistryYieldsGenericOutcome()

    /**
     * A non-2xx status is unreachable, not a successful empty result.
     *
     * @return void
     */
    public function testNon2xxStatusIsUnreachable(): void
    {
        $this->configure(url: 'https://93.184.216.34/');
        $this->stubResponse(body: 'nope', status: 503);

        $result = $this->service()->search(descriptor: $this->descriptor());

        self::assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $result['outcome']);

    }//end testNon2xxStatusIsUnreachable()

    /**
     * An unparseable body is distinguishable from an empty one.
     *
     * @return void
     */
    public function testUnparseableBodyYieldsInvalidOutcome(): void
    {
        $this->configure(url: 'https://93.184.216.34/');
        $this->stubResponse(body: '<html>not json</html>');

        $result = $this->service()->search(descriptor: $this->descriptor());

        self::assertSame(GenericStoreService::OUTCOME_INVALID, $result['outcome']);

    }//end testUnparseableBodyYieldsInvalidOutcome()

    /**
     * The descriptor drives the URL, so two apps hit two different schemas —
     * the whole point of generalising the service.
     *
     * @return void
     */
    public function testDescriptorDrivesTheRemoteUrl(): void
    {
        $this->configure(url: 'https://93.184.216.34/', register: 'openconnector');
        $url = null;
        $this->stubResponse(body: json_encode(['results' => []]), url: $url);

        $this->service()->search(
            descriptor: $this->descriptor(appId: 'openconnector', schema: 'catalog_item')
        );

        self::assertStringContainsString('/apps/openregister/api/objects/openconnector/catalog_item', (string) $url);

    }//end testDescriptorDrivesTheRemoteUrl()

    /**
     * resolve() trusts the returned slug, not the filter — a registry that
     * ignores an unknown query param must not yield an arbitrary first row.
     *
     * @return void
     */
    public function testResolveRejectsAMismatchedSlug(): void
    {
        $this->configure(url: 'https://93.184.216.34/');
        $this->stubResponse(body: json_encode(['results' => [['slug' => 'something-else', 'title' => 'Other']]]));

        self::assertNull($this->service()->resolve(descriptor: $this->descriptor(), slug: 'crm'));

    }//end testResolveRejectsAMismatchedSlug()

    /**
     * resolve() returns the FULL payload (install needs the manifest), unlike
     * search() which returns trimmed cards.
     *
     * @return void
     */
    public function testResolveReturnsTheFullPayload(): void
    {
        $this->configure(url: 'https://93.184.216.34/');
        $this->stubResponse(
            body: json_encode(['results' => [['slug' => 'crm', 'manifest' => ['pages' => []]]]])
        );

        $resolved = $this->service()->resolve(descriptor: $this->descriptor(), slug: 'crm');

        self::assertIsArray($resolved);
        self::assertArrayHasKey('manifest', $resolved);

    }//end testResolveReturnsTheFullPayload()
}//end class
