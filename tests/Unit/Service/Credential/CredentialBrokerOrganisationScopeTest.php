<?php

/**
 * CredentialBrokerOrganisationScopeTest — the additive organisation guard branch (D3).
 *
 * Pins the strictly-additive change: an organisation credential is admitted only
 * for a REAL-session member of its organisation (via UserService/OrganisationService)
 * whose app is allowed, with the secret read from the SYSTEM vault owner; a
 * non-member is denied before any provider call; an organisation call without a
 * session is denied (no sessionless `actingUserId` fallback); and — the guard
 * invariant — a PERSONAL credential is NEVER routed through the membership branch
 * (a non-owner is denied and membership is never even consulted).
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
 * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md#organisation-broker-guard
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Credential\CredentialBrokerService
 */
class CredentialBrokerOrganisationScopeTest extends TestCase
{
    private const UUID = 'cred-org-1';

    private const ORG_UUID = 'org-uuid-tender-office';

    /**
     * The github catalogue entry used across the tests.
     *
     * @return array<string, mixed>
     */
    private function githubProvider(): array
    {
        return [
            'identifier' => 'github',
            'baseUrl'    => 'https://api.github.com',
            'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
            'allowRules' => [['method' => 'GET', 'pathPattern' => '/repos/*']],
        ];
    }

    /**
     * An authenticated MEMBER of the org drives the credential — admitted, secret
     * read from the SYSTEM vault owner ('organisation' scope), personal owner
     * branch never evaluated.
     */
    public function testOrganisationMemberDrivesCredential(): void
    {
        $store = $this->createMock(CredentialStore::class);
        // The vault owner is derived from scope: the broker MUST read with 'organisation'.
        $store->expects($this->once())->method('get')
            ->with(self::UUID, 'organisation')
            ->willReturn('ORG-SECRET');

        $orgService = $this->createMock(OrganisationService::class);
        $orgService->method('hasAccessToOrganisation')->with(self::ORG_UUID)->willReturn(true);

        $client = $this->makeClient(status: 200);
        $client->expects($this->once())->method('request');

        $broker = $this->makeBroker(
            sessionUid: 'member-bob',
            store: $store,
            orgService: $orgService,
            client: $client
        );

        $result = $broker->request(
            credentialId: self::UUID,
            appId: 'hermiq',
            method: 'GET',
            path: '/repos/Conduction/openregister'
        );

        $this->assertSame(200, $result['status']);
    }

    /**
     * A non-member is denied before any provider call is made.
     */
    public function testNonMemberIsDenied(): void
    {
        $orgService = $this->createMock(OrganisationService::class);
        $orgService->method('hasAccessToOrganisation')->willReturn(false);

        $client = $this->createMock(IClient::class);
        $client->expects($this->never())->method('request');

        $broker = $this->makeBroker(
            sessionUid: 'stranger',
            orgService: $orgService,
            client: $client
        );

        $this->expectException(CredentialAccessDeniedException::class);
        $broker->request(
            credentialId: self::UUID,
            appId: 'hermiq',
            method: 'GET',
            path: '/repos/Conduction/openregister'
        );
    }

    /**
     * An organisation call with NO session is denied — the sessionless acting-user
     * fallback applies to personal credentials only.
     */
    public function testOrganisationCallRequiresSession(): void
    {
        $orgService = $this->createMock(OrganisationService::class);
        // Membership must never even be consulted without a session.
        $orgService->expects($this->never())->method('hasAccessToOrganisation');

        $client = $this->createMock(IClient::class);
        $client->expects($this->never())->method('request');

        $broker = $this->makeBroker(
            sessionUid: null,
            orgService: $orgService,
            client: $client
        );

        $this->expectException(CredentialAccessDeniedException::class);
        $broker->request(
            credentialId: self::UUID,
            appId: 'hermiq',
            method: 'GET',
            path: '/repos/Conduction/openregister',
            actingUserId: 'member-bob'
        );
    }

    /**
     * A member whose app is NOT in allowedApps is denied (allowedApps guard still runs).
     */
    public function testNonAllowedAppIsDenied(): void
    {
        $orgService = $this->createMock(OrganisationService::class);
        $orgService->method('hasAccessToOrganisation')->willReturn(true);

        $client = $this->createMock(IClient::class);
        $client->expects($this->never())->method('request');

        $broker = $this->makeBroker(
            sessionUid: 'member-bob',
            orgService: $orgService,
            client: $client
        );

        $this->expectException(CredentialAccessDeniedException::class);
        $broker->request(
            credentialId: self::UUID,
            appId: 'not-allowed-app',
            method: 'GET',
            path: '/repos/Conduction/openregister'
        );
    }

    /**
     * Guard invariant: a PERSONAL credential is never routed through the org
     * membership branch. A non-owner is denied and membership is never consulted,
     * so the org branch can never leak into (widen) the personal one.
     */
    public function testPersonalCredentialNeverEntersOrganisationBranch(): void
    {
        $orgService = $this->createMock(OrganisationService::class);
        $orgService->expects($this->never())->method('hasAccessToOrganisation');

        $store = $this->createMock(CredentialStore::class);
        // Secret is never read: the owner guard denies first.
        $store->expects($this->never())->method('get');

        $client = $this->createMock(IClient::class);
        $client->expects($this->never())->method('request');

        // Personal credential owned by alice; session is bob (a non-owner who might
        // well be a member of some organisation — irrelevant to the personal path).
        $broker = $this->makeBroker(
            sessionUid: 'bob',
            store: $store,
            orgService: $orgService,
            client: $client,
            credData: [
                'name'        => 'Personal GitHub',
                'provider'    => 'github',
                'owner'       => 'alice',
                'allowedApps' => ['hermiq'],
            ],
            ownerUid: 'alice'
        );

        $this->expectException(CredentialAccessDeniedException::class);
        $broker->request(
            credentialId: self::UUID,
            appId: 'hermiq',
            method: 'GET',
            path: '/repos/Conduction/openregister'
        );
    }

    /**
     * Build a broker over an organisation credential (default) or a supplied one.
     *
     * @param string|null              $sessionUid Session user id, or null for sessionless.
     * @param CredentialStore|null     $store      Optional store override.
     * @param OrganisationService|null $orgService Optional organisation service override.
     * @param IClient|null             $client     Optional HTTP client override.
     * @param array<string, mixed>|null $credData  Optional credential property bag.
     * @param string                   $ownerUid   The credential @self owner uid.
     */
    private function makeBroker(
        ?string $sessionUid,
        ?CredentialStore $store=null,
        ?OrganisationService $orgService=null,
        ?IClient $client=null,
        ?array $credData=null,
        string $ownerUid='provisioning-admin'
    ): CredentialBrokerService {
        if ($credData === null) {
            $credData = [
                'name'         => 'Tender-office GitHub',
                'provider'     => 'github',
                'owner'        => $ownerUid,
                'scope'        => 'organisation',
                'organisation' => self::ORG_UUID,
                'allowedApps'  => ['hermiq'],
            ];
        }

        $entity = new ObjectEntity();
        $entity->setUuid(self::UUID);
        $entity->setOwner($ownerUid);
        $entity->setObject($credData);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($entity);

        if ($store === null) {
            $store = $this->createMock(CredentialStore::class);
            $store->method('get')->willReturn('ORG-SECRET');
        }

        if ($orgService === null) {
            $orgService = $this->createMock(OrganisationService::class);
            $orgService->method('hasAccessToOrganisation')->willReturn(true);
        }

        $user = null;
        if ($sessionUid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($sessionUid);
        }

        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $catalogue = $this->createMock(ProviderCatalogue::class);
        $catalogue->method('get')->willReturn($this->githubProvider());

        if ($client === null) {
            $client = $this->makeClient(status: 200);
        }

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        return new CredentialBrokerService(
            $objectService,
            $store,
            $catalogue,
            $session,
            $clientService,
            $this->createMock(LoggerInterface::class),
            $orgService
        );
    }

    /**
     * A mock HTTP client returning a fixed status with an empty body.
     *
     * @param int $status The upstream status code.
     *
     * @return IClient&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeClient(int $status): IClient
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getHeaders')->willReturn([]);
        $response->method('getBody')->willReturn('{}');

        $client = $this->createMock(IClient::class);
        $client->method('request')->willReturn($response);

        return $client;
    }
}//end class
