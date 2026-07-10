<?php

/**
 * CredentialBrokerServiceTest — the four ordered guards + secret-injecting call.
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
class CredentialBrokerServiceTest extends TestCase
{
    /** @var array<string, mixed>|null Captured client->request() options. */
    private ?array $capturedOptions = null;

    /**
     * The github catalogue entry used across the happy-path tests.
     *
     * @return array<string, mixed>
     */
    private function githubProvider(): array
    {
        return [
            'identifier' => 'github',
            'baseUrl'    => 'https://api.github.com',
            'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
            'allowRules' => [
                ['method' => 'GET', 'pathPattern' => '/repos/*'],
                ['method' => 'GET', 'pathPattern' => '/user/repos'],
            ],
        ];
    }

    /**
     * Wire a broker with fully-mocked collaborators.
     *
     * @param array<string, mixed> $credData
     */
    private function makeService(
        string $sessionUid,
        string $ownerUid,
        array $credData,
        ?array $provider,
        ?string $secret
    ): CredentialBrokerService {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($sessionUid);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        // A real ObjectEntity — getOwner()/jsonSerialize() are magic accessors
        // that cannot be stubbed on a mock.
        $entity = new ObjectEntity();
        $entity->setOwner($ownerUid);
        $entity->setObject($credData);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($entity);

        $catalogue = $this->createMock(ProviderCatalogue::class);
        $catalogue->method('get')->willReturn($provider);

        $store = $this->createMock(CredentialStore::class);
        $store->method('get')->willReturn($secret);

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->willReturn(['Content-Type' => ['application/json']]);
        $response->method('getBody')->willReturn('{"full_name":"Conduction/openregister"}');

        $client = $this->createMock(IClient::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $uri, array $options) use ($response) {
                $this->capturedOptions = $options;
                return $response;
            }
        );
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        return new CredentialBrokerService(
            $objectService,
            $store,
            $catalogue,
            $session,
            $clientService,
            $this->createMock(LoggerInterface::class),
            $this->createMock(OrganisationService::class)
        );
    }

    public function testOwnerGuardRejectsNonOwner(): void
    {
        $service = $this->makeService(
            'alice',
            'bob',
            ['provider' => 'github', 'allowedApps' => ['hermiq']],
            $this->githubProvider(),
            'SECRET123'
        );

        $this->expectException(CredentialAccessDeniedException::class);
        $service->request('cred-1', 'hermiq', 'GET', '/repos/Conduction/openregister');
    }

    public function testDisallowedAppRejected(): void
    {
        $service = $this->makeService(
            'alice',
            'alice',
            ['provider' => 'github', 'allowedApps' => ['someoneelse']],
            $this->githubProvider(),
            'SECRET123'
        );

        $this->expectException(CredentialAccessDeniedException::class);
        $service->request('cred-1', 'hermiq', 'GET', '/repos/Conduction/openregister');
    }

    public function testDisallowedMethodPathRejected(): void
    {
        $service = $this->makeService(
            'alice',
            'alice',
            ['provider' => 'github', 'allowedApps' => ['hermiq']],
            $this->githubProvider(),
            'SECRET123'
        );

        // DELETE is not in the github allow-rules.
        $this->expectException(CredentialAccessDeniedException::class);
        $service->request('cred-1', 'hermiq', 'DELETE', '/repos/Conduction/openregister');
    }

    public function testPathTraversalRejected(): void
    {
        $service = $this->makeService(
            'alice',
            'alice',
            ['provider' => 'github', 'allowedApps' => ['hermiq']],
            $this->githubProvider(),
            'SECRET123'
        );

        $this->expectException(CredentialAccessDeniedException::class);
        $service->request('cred-1', 'hermiq', 'GET', '/repos/../../admin');
    }

    public function testHappyPathInjectsAuthAndReturnsUpstream(): void
    {
        $service = $this->makeService(
            'alice',
            'alice',
            ['provider' => 'github', 'allowedApps' => ['hermiq']],
            $this->githubProvider(),
            'SECRET123'
        );

        $result = $service->request('cred-1', 'hermiq', 'GET', '/repos/Conduction/openregister');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('full_name', $result['body']);

        // The provider auth scheme injected the secret into the Authorization header.
        $this->assertSame('token SECRET123', $this->capturedOptions['headers']['Authorization']);
    }

    public function testSecretNeverAppearsInReturnValue(): void
    {
        $service = $this->makeService(
            'alice',
            'alice',
            ['provider' => 'github', 'allowedApps' => ['hermiq']],
            $this->githubProvider(),
            'SUPERSECRETTOKEN'
        );

        $result  = $service->request('cred-1', 'hermiq', 'GET', '/repos/Conduction/openregister');
        $encoded = json_encode($result);

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('SUPERSECRETTOKEN', $encoded);
    }
}//end class
