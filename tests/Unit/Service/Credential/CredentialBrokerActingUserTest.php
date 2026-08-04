<?php

/**
 * CredentialBrokerActingUserTest — D-K acting-user semantics + the D-F IDOR pin.
 *
 * Pins: a session identity wins UNCONDITIONALLY over any `actingUserId` (a
 * session caller can never impersonate another user); a sessionless in-process
 * caller may act for the credential owner via `actingUserId`; a sessionless
 * call without one denies as before; the HTTP controller NEVER forwards an
 * acting user (any request-supplied value is ignored entirely); and — the D-F
 * compensating pin — user B can never broker user A's credential on the
 * Doriath path: the owner guard denies BEFORE any store read occurs.
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
 * @spec openspec/changes/credential-doriath-leaf/specs/credential-broker/spec.md#background-acting-user-resolution
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Controller\CredentialController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\DoriathCredentialStore;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CredentialBrokerActingUserTest extends TestCase
{
    private const UUID = '00000000-0000-0000-0000-000000000000';

    private const SECRET = 'YOUR_API_KEY_HERE';

    /**
     * D-K: with an active session, actingUserId is ignored — the session user
     * (bob) is checked against the owner (alice) and denied.
     */
    public function testSessionIdentityWinsOverActingUserId(): void
    {
        $client = $this->createMock(IClient::class);
        $client->expects($this->never())->method('request');

        $broker = $this->makeBroker(sessionUid: 'bob', client: $client);

        $this->expectException(CredentialAccessDeniedException::class);
        $broker->request(
            credentialId: self::UUID,
            appId: 'spectr',
            method: 'GET',
            path: '/notices',
            actingUserId: 'alice'
        );
    }

    /**
     * D-K happy path: a sessionless in-process caller acting for the owner
     * passes the owner guard and the call proceeds through the chain.
     */
    public function testSessionlessActingUserIsHonored(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->willReturn([]);
        $response->method('getBody')->willReturn('{"hits":[]}');

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())->method('request')->willReturn($response);

        $broker = $this->makeBroker(sessionUid: null, client: $client);

        $result = $broker->request(
            credentialId: self::UUID,
            appId: 'spectr',
            method: 'GET',
            path: '/notices',
            actingUserId: 'alice'
        );

        $this->assertSame(200, $result['status']);
    }

    /**
     * Sessionless without an acting user stays denied (unchanged behaviour).
     */
    public function testSessionlessWithoutActingUserIsDenied(): void
    {
        $client = $this->createMock(IClient::class);
        $client->expects($this->never())->method('request');

        $broker = $this->makeBroker(sessionUid: null, client: $client);

        $this->expectException(CredentialAccessDeniedException::class);
        $broker->request(
            credentialId: self::UUID,
            appId: 'spectr',
            method: 'GET',
            path: '/notices'
        );
    }

    /**
     * Edge: an empty-string acting user is not an identity — denied.
     */
    public function testSessionlessEmptyActingUserIsDenied(): void
    {
        $client = $this->createMock(IClient::class);
        $client->expects($this->never())->method('request');

        $broker = $this->makeBroker(sessionUid: null, client: $client);

        $this->expectException(CredentialAccessDeniedException::class);
        $broker->request(
            credentialId: self::UUID,
            appId: 'spectr',
            method: 'GET',
            path: '/notices',
            actingUserId: ''
        );
    }

    /**
     * D-F IDOR pin: user B can never broker user A's credential on the Doriath
     * path — the owner guard denies BEFORE any store read occurs.
     */
    public function testUserBCannotBrokerUserAsCredentialOnDoriathPath(): void
    {
        $doriathStore = $this->createMock(DoriathCredentialStore::class);
        $doriathStore->expects($this->never())->method('get');

        $client = $this->createMock(IClient::class);
        $client->expects($this->never())->method('request');

        $broker = $this->makeBroker(sessionUid: 'bob', client: $client, store: $doriathStore);

        $this->expectException(CredentialAccessDeniedException::class);
        $broker->request(
            credentialId: self::UUID,
            appId: 'spectr',
            method: 'GET',
            path: '/notices'
        );
    }

    /**
     * D-K: the HTTP controller NEVER forwards an acting user — a request
     * carrying `actingUserId` reaches the broker with NO acting user set.
     */
    public function testHttpControllerNeverForwardsActingUser(): void
    {
        $capturedActingUserId = 'sentinel-not-called';

        $broker = $this->createMock(CredentialBrokerService::class);
        $broker->method('request')->willReturnCallback(
            function (
                string $credentialId,
                string $appId,
                string $method,
                string $path,
                array $headers=[],
                ?string $body=null,
                ?string $actingUserId=null
            ) use (&$capturedActingUserId): array {
                $capturedActingUserId = $actingUserId;
                return ['status' => 200, 'headers' => [], 'body' => '{}'];
            }
        );

        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->with('X-Credential-Token')->willReturn('token-placeholder');
        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                // A malicious/misguided HTTP caller supplies an acting user.
                $params = [
                    'method'       => 'GET',
                    'path'         => '/notices',
                    'headers'      => [],
                    'body'         => null,
                    'actingUserId' => 'mallory',
                ];
                return ($params[$key] ?? $default);
            }
        );

        $tokenService = $this->createMock(CredentialAppTokenService::class);
        $tokenService->method('verify')->willReturn(['appId' => 'spectr', 'credentialId' => self::UUID]);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $controller = new CredentialController(
            'openregister',
            $request,
            $userSession,
            $this->createMock(IGroupManager::class),
            $this->createMock(ObjectService::class),
            $this->createMock(CredentialStore::class),
            $this->createMock(ProviderCatalogue::class),
            $broker,
            $tokenService,
            $this->createMock(OrganisationService::class),
            new SharePrincipalDeriver()
        );

        $response = $controller->brokerRequest(self::UUID);

        $this->assertSame(200, $response->getStatus());
        $this->assertNull($capturedActingUserId, 'HTTP path must never forward an acting user');
    }

    /**
     * Build a broker over a doffin credential owned by `alice`.
     *
     * @param string|null          $sessionUid Session user id, or null for sessionless.
     * @param IClient              $client     The (mock) HTTP client.
     * @param CredentialStore|null $store      Optional store override (defaults to a stub returning the secret).
     */
    private function makeBroker(?string $sessionUid, IClient $client, ?CredentialStore $store=null): CredentialBrokerService
    {
        $credential = new ObjectEntity();
        $credential->setUuid(self::UUID);
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

        if ($store === null) {
            $store = $this->createMock(CredentialStore::class);
            $store->method('get')->willReturn(self::SECRET);
        }

        $user = null;
        if ($sessionUid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($sessionUid);
        }

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppPath')->with('openregister')->willReturn(dirname(__DIR__, 4));
        $catalogue = new ProviderCatalogue($appManager, $this->createMock(LoggerInterface::class));

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        return new CredentialBrokerService(
            $objectService,
            $store,
            $catalogue,
            $userSession,
            $clientService,
            $this->createMock(LoggerInterface::class),
            $this->createMock(OrganisationService::class)
        );
    }
}
