<?php

/**
 * DoffinProviderTest — pins the `doffin` provider catalogue entry and the broker
 * guard behaviour it implies (credential-provider-doffin change).
 *
 * Covers: catalogue entry shape (host-locked baseUrl, subscription-key header,
 * GET-only allow-rules), broker rule matching for `GET /notices` incl. query
 * strings, denial of non-GET and unlisted paths (fail-closed static 403 path),
 * and the host-lock + auth-header injection on the happy path. Secrets in this
 * file are placeholders only (`YOUR_API_KEY_HERE`).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credential-provider-doffin/specs/credential-broker/spec.md#doffin-provider-entry
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DoffinProviderTest extends TestCase
{
    private const PLACEHOLDER_KEY = 'YOUR_API_KEY_HERE';

    private const CREDENTIAL_UUID = '00000000-0000-0000-0000-000000000000';

    private ProviderCatalogue $catalogue;

    protected function setUp(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppPath')
            ->with('openregister')
            ->willReturn(dirname(__DIR__, 4));

        $this->catalogue = new ProviderCatalogue(
            $appManager,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * Happy path (catalogue): the doffin entry exists, host-locked, GET-only.
     */
    public function testCatalogueContainsDoffinEntryHostLockedAndGetOnly(): void
    {
        $entry = $this->catalogue->get('doffin');

        $this->assertIsArray($entry);
        $this->assertSame('doffin', $entry['identifier']);
        $this->assertSame('Doffin (Norway)', $entry['title']);
        $this->assertSame('https://betaapi.doffin.no/public/v2', $entry['baseUrl']);
        $this->assertSame('betaapi.doffin.no', parse_url((string) $entry['baseUrl'], PHP_URL_HOST));

        $this->assertSame('Ocp-Apim-Subscription-Key', $entry['authScheme']['header']);
        $this->assertSame('{secret}', $entry['authScheme']['template']);

        $this->assertSame(
            [['method' => 'GET', 'pathPattern' => '/notices']],
            $entry['allowRules'],
            'The doffin rule set must be exactly one GET-only /notices rule'
        );
        foreach ($entry['allowRules'] as $rule) {
            $this->assertSame('GET', $rule['method'], 'No non-GET rule may exist for doffin');
        }
    }

    /**
     * Regression pin: adding doffin leaves the github/gitlab entries untouched.
     */
    public function testExistingProvidersUnchangedByDoffinAddition(): void
    {
        $github = $this->catalogue->get('github');
        $gitlab = $this->catalogue->get('gitlab');

        $this->assertIsArray($github);
        $this->assertSame('https://api.github.com', $github['baseUrl']);
        $this->assertSame('token {secret}', $github['authScheme']['template']);
        $this->assertCount(4, $github['allowRules']);

        $this->assertIsArray($gitlab);
        $this->assertSame('https://gitlab.com/api/v4', $gitlab['baseUrl']);
        $this->assertSame('Bearer {secret}', $gitlab['authScheme']['template']);
        $this->assertCount(2, $gitlab['allowRules']);
    }

    /**
     * Happy path (broker): GET /notices with query params is allowed, the URL is
     * host-locked to betaapi.doffin.no, and the subscription key header carries
     * the bare secret — any caller-supplied value for it is discarded.
     */
    public function testBrokerAllowsNoticeSearchAndInjectsSubscriptionKey(): void
    {
        $captured = [];

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->willReturn([]);
        $response->method('getBody')->willReturn('{"hits":[]}');

        $client = $this->createMock(IClient::class);
        $client->method('request')
            ->willReturnCallback(
                function (string $method, string $url, array $options) use (&$captured, $response) {
                    $captured = ['method' => $method, 'url' => $url, 'options' => $options];
                    return $response;
                }
            );

        $broker = $this->makeBroker(client: $client);

        $result = $broker->request(
            credentialId: self::CREDENTIAL_UUID,
            appId: 'spectr',
            method: 'GET',
            path: '/notices?cpvCodes=48000000&pageNumber=1&pageSize=25',
            headers: ['Ocp-Apim-Subscription-Key' => 'caller-supplied-must-be-discarded']
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame(
            'https://betaapi.doffin.no/public/v2/notices?cpvCodes=48000000&pageNumber=1&pageSize=25',
            $captured['url']
        );
        $this->assertSame('betaapi.doffin.no', parse_url($captured['url'], PHP_URL_HOST), 'Host-lock');
        $this->assertSame(
            self::PLACEHOLDER_KEY,
            $captured['options']['headers']['Ocp-Apim-Subscription-Key'],
            'Bare secret, no prefix, caller-supplied value discarded'
        );
    }

    /**
     * Error path: POST /notices matches no doffin allow-rule and is denied
     * before any outbound call is made (fail closed).
     */
    public function testBrokerDeniesPostNotices(): void
    {
        $client = $this->createMock(IClient::class);
        $client->expects($this->never())->method('request');

        $broker = $this->makeBroker(client: $client);

        $this->expectException(CredentialAccessDeniedException::class);
        $broker->request(
            credentialId: self::CREDENTIAL_UUID,
            appId: 'spectr',
            method: 'POST',
            path: '/notices'
        );
    }

    /**
     * Edge: GET on any path other than /notices (including sub-paths) is denied.
     */
    public function testBrokerDeniesUnlistedGetPaths(): void
    {
        $client = $this->createMock(IClient::class);
        $client->expects($this->never())->method('request');

        $broker = $this->makeBroker(client: $client);

        $this->expectException(CredentialAccessDeniedException::class);
        $broker->request(
            credentialId: self::CREDENTIAL_UUID,
            appId: 'spectr',
            method: 'GET',
            path: '/notices/12345'
        );
    }

    /**
     * Build a broker wired to a doffin credential owned by the session user.
     *
     * @param IClient $client The (mock) HTTP client observing/denying the outbound call.
     */
    private function makeBroker(IClient $client): CredentialBrokerService
    {
        $credential = new ObjectEntity();
        $credential->setUuid(self::CREDENTIAL_UUID);
        $credential->setOwner('alice');
        $credential->setObject(
            [
                'name'        => 'Doffin subscription key',
                'provider'    => 'doffin',
                'owner'       => 'alice',
                'allowedApps' => ['spectr'],
                'createdAt'   => '2026-07-06T00:00:00+00:00',
            ]
        );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($credential);

        $store = $this->createMock(CredentialStore::class);
        $store->method('get')->with(self::CREDENTIAL_UUID)->willReturn(self::PLACEHOLDER_KEY);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        return new CredentialBrokerService(
            $objectService,
            $store,
            $this->catalogue,
            $userSession,
            $clientService,
            $this->createMock(LoggerInterface::class)
        );
    }
}
