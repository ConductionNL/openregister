<?php

/**
 * Integration tests for the RBAC scope discovery endpoint.
 *
 * Closes the rbac-scopes spec requirement "Scope Documentation and
 * Discovery API" — clients query `GET /api/scopes` to learn the
 * effective (register, schema, action) scopes for the active user
 * without probing every endpoint.
 *
 * The tests cover:
 *
 *   1. Admin callers receive every (register, schema) pair with the
 *      complete five-action vocabulary — mirrors the admin-bypass in
 *      `PermissionHandler::hasPermission`.
 *   2. Non-admin callers see ONLY the actions they're permitted on a
 *      schema with explicit `authorization` rules; restricted actions
 *      (here: `update`/`delete` gated to the `admin` group) MUST NOT
 *      appear in the response.
 *   3. The `register=` query filter narrows the response to a single
 *      register's surface.
 *   4. The response envelope shape — user / isAdmin / groups / scopes —
 *      matches the contract documented on the controller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Controller\ScopesController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class RbacScopeDiscoveryIntegrationTest extends TestCase
{

    private ScopesController $controller;

    private RegisterMapper $registerMapper;

    private SchemaMapper $schemaMapper;

    private IUserManager $userManager;

    private IUserSession $userSession;

    private ?IUser $previousUser = null;

    private ?IUser $testUser = null;

    private string $testUserId = '';

    private ?Register $testRegister = null;

    private ?Schema $openSchema = null;

    private ?Schema $gatedSchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller     = \OC::$server->get(ScopesController::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->userManager    = \OC::$server->get(IUserManager::class);
        $this->userSession    = \OC::$server->get(IUserSession::class);

        $this->previousUser = $this->userSession->getUser();

        // Random non-compromised password — password_policy app rejects
        // common test strings.
        $this->testUserId = 'phpunit-rbac-scope-'.uniqid();
        $password         = bin2hex(random_bytes(12)).'Aa9!';
        $this->testUser   = $this->userManager->createUser($this->testUserId, $password);

        $this->createTestFixture();

    }//end setUp()

    protected function tearDown(): void
    {
        if ($this->previousUser !== null) {
            $this->userSession->setUser($this->previousUser);
        }

        if ($this->testUser !== null) {
            try {
                $this->testUser->delete();
            } catch (\Throwable) {
            }
        }

        $db = \OC::$server->get(\OCP\IDBConnection::class);
        foreach ([$this->openSchema, $this->gatedSchema] as $schema) {
            if ($schema === null) {
                continue;
            }

            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        if ($this->testRegister !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testRegister->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();

    }//end tearDown()

    public function testEnvelopeShapeMatchesContract(): void
    {
        $this->userSession->setUser($this->testUser);

        $body = $this->extractBody(
            $this->controller->index(register: $this->testRegister->getSlug())
        );

        $this->assertSame($this->testUserId, $body['user'] ?? null, 'envelope MUST report the active user uid');
        $this->assertSame(false, $body['isAdmin'] ?? null, 'fresh user is not admin');
        $this->assertIsArray($body['groups'] ?? null);
        $this->assertIsArray($body['scopes'] ?? null);

    }//end testEnvelopeShapeMatchesContract()

    public function testNonAdminSeesOnlyPermittedActions(): void
    {
        $this->userSession->setUser($this->testUser);

        $body   = $this->extractBody(
            $this->controller->index(register: $this->testRegister->getSlug())
        );
        $scopes = $body['scopes'] ?? [];

        $this->assertNotEmpty($scopes, 'discovery response MUST include at least one scope tuple');

        $byPair = [];
        foreach ($scopes as $entry) {
            $byPair[$entry['register'].'|'.$entry['schema']] = $entry['actions'];
        }

        // Open schema: caller is granted `read` + `list` on the public
        // group and `update` on the gated `admin` group. The non-admin
        // user's effective set MUST contain `read` + `list` and MUST
        // NOT contain `update` or `delete`.
        $openKey = $this->testRegister->getSlug().'|'.$this->openSchema->getSlug();
        $this->assertArrayHasKey($openKey, $byPair, 'open schema MUST appear in scopes for the non-admin');
        $this->assertContains('read', $byPair[$openKey], 'public read MUST grant read');
        $this->assertContains('list', $byPair[$openKey], 'public list MUST grant list');
        $this->assertNotContains('update', $byPair[$openKey], 'admin-gated update MUST NOT appear for non-admin');
        $this->assertNotContains('delete', $byPair[$openKey], 'admin-gated delete MUST NOT appear for non-admin');

        // Gated schema: ONLY admin can do anything. Non-admin MUST NOT
        // see this pair in the response (collectActionsForUser returns
        // an empty array, which the controller skips).
        $gatedKey = $this->testRegister->getSlug().'|'.$this->gatedSchema->getSlug();
        $this->assertArrayNotHasKey(
            $gatedKey,
            $byPair,
            'fully-gated schema MUST be omitted when the user has zero actions'
        );

    }//end testNonAdminSeesOnlyPermittedActions()

    public function testAdminBypassReturnsAllFiveActions(): void
    {
        // Admin bypass uses `previousUser` which is the test runner —
        // typically `admin` in the Docker dev env. Skip if not.
        $admin = $this->userManager->get('admin');
        if ($admin === null) {
            $this->markTestSkipped('test bench requires the admin user; not present in this env');
        }

        $this->userSession->setUser($admin);

        $body   = $this->extractBody(
            $this->controller->index(register: $this->testRegister->getSlug())
        );
        $scopes = $body['scopes'] ?? [];

        $this->assertSame(true, $body['isAdmin'] ?? null, 'admin caller MUST be flagged isAdmin=true');
        $this->assertNotEmpty($scopes, 'admin caller MUST receive every (register, schema) tuple');

        foreach ($scopes as $entry) {
            $this->assertSame(
                ScopesController::ACTIONS,
                $entry['actions'],
                'admin bypass MUST surface the full canonical action vocabulary on every tuple'
            );
        }

    }//end testAdminBypassReturnsAllFiveActions()

    public function testRegisterFilterNarrowsResponseToOnePair(): void
    {
        $this->userSession->setUser($this->testUser);

        $body   = $this->extractBody(
            $this->controller->index(register: $this->testRegister->getSlug())
        );
        $scopes = $body['scopes'] ?? [];

        $registers = array_unique(array_column($scopes, 'register'));
        $this->assertSame(
            [$this->testRegister->getSlug()],
            array_values($registers),
            'register filter MUST limit the response to that register'
        );

    }//end testRegisterFilterNarrowsResponseToOnePair()

    public function testUnknownRegisterFilterReturnsEmptyScopes(): void
    {
        $this->userSession->setUser($this->testUser);

        $body = $this->extractBody(
            $this->controller->index(register: 'phpunit-no-such-register-xyz')
        );

        $this->assertSame([], $body['scopes'] ?? null, 'unknown register filter MUST yield zero scopes');

    }//end testUnknownRegisterFilterReturnsEmptyScopes()

    /**
     * Pull the JSON body from a controller response.
     *
     * @return array<string, mixed>
     */
    private function extractBody(JSONResponse $response): array
    {
        return (array) $response->getData();

    }//end extractBody()

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-rbac-scope-'.uniqid());
        $register->setSlug('phpunit-rbac-scope-'.uniqid());
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setVersion('1.0.0');
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        // Open schema: public read + list, admin-gated update + delete.
        $open = new Schema();
        $open->setTitle('phpunit-rbac-scope-open-'.uniqid());
        $open->setSlug('phpunit-rbac-scope-open-'.uniqid());
        $open->setUuid(Uuid::v4()->toRfc4122());
        $open->setProperties(['title' => ['type' => 'string', 'title' => 'Title']]);
        $open->setAuthorization(
            [
                'read'   => [['group' => 'public']],
                'list'   => [['group' => 'public']],
                'create' => [['group' => 'admin']],
                'update' => [['group' => 'admin']],
                'delete' => [['group' => 'admin']],
            ]
        );
        $this->openSchema = $this->schemaMapper->insert($open);

        // Gated schema: every action requires admin.
        $gated = new Schema();
        $gated->setTitle('phpunit-rbac-scope-gated-'.uniqid());
        $gated->setSlug('phpunit-rbac-scope-gated-'.uniqid());
        $gated->setUuid(Uuid::v4()->toRfc4122());
        $gated->setProperties(['title' => ['type' => 'string', 'title' => 'Title']]);
        $gated->setAuthorization(
            [
                'read'   => [['group' => 'admin']],
                'list'   => [['group' => 'admin']],
                'create' => [['group' => 'admin']],
                'update' => [['group' => 'admin']],
                'delete' => [['group' => 'admin']],
            ]
        );
        $this->gatedSchema = $this->schemaMapper->insert($gated);

        $this->testRegister->setSchemas([$this->openSchema->getId(), $this->gatedSchema->getId()]);
        $this->registerMapper->update($this->testRegister);

    }//end createTestFixture()
}//end class
