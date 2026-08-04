<?php

/**
 * CredentialControllerOrganisationTest — the organisation CRUD + listing gate (D4).
 *
 * Pins: only an organisation administrator (or NC admin) may create an
 * organisation credential (a member is denied); the organisation defaults to the
 * caller's active organisation when omitted; the secret is written to the vault
 * under the 'organisation' scope; a member lists the active organisation's
 * credential metadata with no secret; and the PERSONAL create path is unchanged
 * (no scope/organisation fields, no admin gate, 'personal' vault scope).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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
 * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md#organisation-credential-administration
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\CredentialController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\CredentialController
 */
class CredentialControllerOrganisationTest extends TestCase
{
    private const ACTIVE_ORG = 'org-active-uuid';

    /** @var array<string, mixed>|null Captured saveObject() payload. */
    private ?array $savedObject = null;

    /** @var ObjectService&\PHPUnit\Framework\MockObject\MockObject */
    private $objectService;

    /** @var CredentialStore&\PHPUnit\Framework\MockObject\MockObject */
    private $store;

    /** @var OrganisationService&\PHPUnit\Framework\MockObject\MockObject */
    private $orgService;

    protected function setUp(): void
    {
        $this->savedObject   = null;
        $this->objectService = $this->createMock(ObjectService::class);
        $this->store         = $this->createMock(CredentialStore::class);
        $this->orgService    = $this->createMock(OrganisationService::class);
    }

    public function testOrgAdminCreatesOrganisationCredential(): void
    {
        $this->orgService->method('isOrganisationAdmin')
            ->with('org-target', 'admin-uid')->willReturn(true);

        $this->stubSaveObject(uuid: 'new-cred-uuid');
        // The secret is written under the 'organisation' vault scope.
        $this->store->expects($this->once())->method('put')
            ->with('new-cred-uuid', 'org-secret', 'organisation');

        $controller = $this->makeController(
            uid: 'admin-uid',
            params: [
                'name'         => 'Tender GitHub',
                'provider'     => 'github',
                'secret'       => 'org-secret',
                'scope'        => 'organisation',
                'organisation' => 'org-target',
            ]
        );

        $response = $controller->create();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('organisation', $this->savedObject['scope']);
        $this->assertSame('org-target', $this->savedObject['organisation']);
        // Owner records the provisioning admin for attribution.
        $this->assertSame('admin-uid', $this->savedObject['owner']);
    }

    public function testNonAdminMemberCannotCreateOrganisationCredential(): void
    {
        $this->orgService->method('isOrganisationAdmin')->willReturn(false);

        $this->objectService->expects($this->never())->method('saveObject');
        $this->store->expects($this->never())->method('put');

        $controller = $this->makeController(
            uid: 'member-uid',
            params: [
                'name'         => 'Tender GitHub',
                'provider'     => 'github',
                'secret'       => 'org-secret',
                'scope'        => 'organisation',
                'organisation' => 'org-target',
            ]
        );

        $response = $controller->create();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testOrganisationDefaultsToActiveOrganisation(): void
    {
        // A real Organisation — getUuid()/setUuid() are magic Entity accessors that
        // cannot be stubbed on a mock.
        $activeOrg = new Organisation();
        $activeOrg->setUuid(self::ACTIVE_ORG);
        $this->orgService->method('getActiveOrganisation')->willReturn($activeOrg);

        // The admin gate must be evaluated against the DEFAULTED active org.
        $this->orgService->expects($this->once())->method('isOrganisationAdmin')
            ->with(self::ACTIVE_ORG, 'admin-uid')->willReturn(true);

        $this->stubSaveObject(uuid: 'new-cred-uuid');

        $controller = $this->makeController(
            uid: 'admin-uid',
            params: [
                'name'     => 'Tender GitHub',
                'provider' => 'github',
                'secret'   => 'org-secret',
                'scope'    => 'organisation',
            ]
        );

        $response = $controller->create();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame(self::ACTIVE_ORG, $this->savedObject['organisation']);
    }

    public function testPersonalCreateIsUnchanged(): void
    {
        // A personal create never consults the organisation admin gate.
        $this->orgService->expects($this->never())->method('isOrganisationAdmin');
        $this->stubSaveObject(uuid: 'personal-uuid');
        $this->store->expects($this->once())->method('put')
            ->with('personal-uuid', 'my-secret', 'personal');

        $controller = $this->makeController(
            uid: 'alice',
            params: [
                'name'     => 'My GitHub',
                'provider' => 'github',
                'secret'   => 'my-secret',
            ]
        );

        $response = $controller->create();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('alice', $this->savedObject['owner']);
        $this->assertArrayNotHasKey('scope', $this->savedObject);
        $this->assertArrayNotHasKey('organisation', $this->savedObject);
    }

    public function testIndexOrganisationListsActiveOrgMetadataWithoutSecrets(): void
    {
        // A real Organisation — getUuid()/setUuid() are magic Entity accessors that
        // cannot be stubbed on a mock.
        $activeOrg = new Organisation();
        $activeOrg->setUuid(self::ACTIVE_ORG);
        $this->orgService->method('getActiveOrganisation')->willReturn($activeOrg);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('findAll')->willReturn(
            [
                $this->credential(['scope' => 'organisation', 'organisation' => self::ACTIVE_ORG, 'name' => 'Active org cred'], 'admin-uid'),
                $this->credential(['scope' => 'organisation', 'organisation' => 'other-org', 'name' => 'Other org cred'], 'admin-uid'),
                $this->credential(['name' => 'A personal cred'], 'member-uid'),
            ]
        );

        $controller = $this->makeController(uid: 'member-uid', params: ['scope' => 'organisation']);

        $response = $controller->index();
        $body     = $response->getData();

        $this->assertCount(1, $body['results']);
        $this->assertSame('Active org cred', $body['results'][0]['name']);
        // No secret value is ever present in listed metadata.
        $this->assertStringNotContainsString('secret', strtolower(json_encode($body['results'])));
    }

    public function testDeleteOrganisationCredentialRequiresAdmin(): void
    {
        $entity = $this->credential(['scope' => 'organisation', 'organisation' => 'org-target'], 'admin-uid');
        $entity->setUuid('cred-uuid');
        $this->objectService->method('find')->willReturn($entity);

        $this->orgService->method('isOrganisationAdmin')
            ->with('org-target', 'member-uid')->willReturn(false);

        $this->objectService->expects($this->never())->method('deleteObject');
        $this->store->expects($this->never())->method('delete');

        $controller = $this->makeController(uid: 'member-uid', params: []);

        $response = $controller->destroy('cred-uuid');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    /**
     * A real ObjectEntity carrying a property bag (magic accessors can't be stubbed).
     *
     * @param array<string, mixed> $data  The credential property bag.
     * @param string               $owner The @self owner uid.
     */
    private function credential(array $data, string $owner): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setOwner($owner);
        $entity->setObject($data);
        return $entity;
    }

    /**
     * Stub saveObject() to capture the payload and return an entity with the uuid.
     *
     * @param string $uuid The uuid the saved entity reports.
     */
    private function stubSaveObject(string $uuid): void
    {
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use ($uuid): ObjectEntity {
                $this->savedObject = $object;
                $entity            = new ObjectEntity();
                $entity->setUuid($uuid);
                $entity->setObject($object);
                return $entity;
            }
        );
    }

    /**
     * Build a controller with a mocked session, request, and collaborators.
     *
     * @param string               $uid    The session user's UID.
     * @param array<string, mixed> $params The request params.
     */
    private function makeController(string $uid, array $params): CredentialController
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $catalogue = $this->createMock(ProviderCatalogue::class);
        $catalogue->method('get')->willReturn(['identifier' => 'github', 'baseUrl' => 'https://api.github.com']);

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) use ($params) {
                return array_key_exists($key, $params) ? $params[$key] : $default;
            }
        );

        // A REAL broker over the same mocked ObjectService / CredentialStore: create()
        // now mints through CredentialBrokerService::mint(), so a mocked broker would
        // silently swallow the save + vault write these tests assert on. The controller
        // behaviour under test (the D4 admin gate, the persisted property bag, the vault
        // scope) is unchanged — only the seam the call travels through moved.
        $broker = new CredentialBrokerService(
            $this->objectService,
            $this->store,
            $catalogue,
            $session,
            $this->createMock(IClientService::class),
            $this->createMock(LoggerInterface::class),
            $this->orgService
        );

        return new CredentialController(
            'openregister',
            $request,
            $session,
            $this->createMock(IGroupManager::class),
            $this->objectService,
            $this->store,
            $catalogue,
            $broker,
            $this->createMock(CredentialAppTokenService::class),
            $this->orgService,
            new SharePrincipalDeriver()
        );
    }
}//end class
