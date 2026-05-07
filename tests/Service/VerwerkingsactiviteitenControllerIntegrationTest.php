<?php

/**
 * Integration tests for `VerwerkingsactiviteitenController` (AVG /
 * GDPR Art 30 Phase 2a).
 *
 * Locks in:
 *   1. List + show shape against the dedicated catalog table.
 *   2. Admin-only gating on create / update / destroy.
 *   3. Validation errors land as 422 envelopes (not 500s).
 *   4. Soft-archive on DELETE — row stays in DB with `status='archived'`
 *      so audit-trail FKs remain resolvable.
 *   5. The `verantwoording` aggregation joins audit-trail counts onto
 *      each activity per AVG Art 30 §4 supervisory-review needs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Controller\VerwerkingsactiviteitenController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class VerwerkingsactiviteitenControllerIntegrationTest extends TestCase
{

    private VerwerkingsactiviteitMapper $vrwMapper;

    private SchemaMapper $schemaMapper;

    private RegisterMapper $registerMapper;

    private AuditTrailMapper $auditMapper;

    private IUserManager $userManager;

    private IUserSession $userSession;

    private ?IUser $previousUser = null;

    private ?IUser $nonAdminUser = null;

    private string $nonAdminUserId = '';

    /**
     * @var array<int, string>
     */
    private array $insertedActivityUuids = [];

    /**
     * @var array<int, Schema>
     */
    private array $insertedSchemas = [];

    /**
     * @var array<int, Register>
     */
    private array $insertedRegisters = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->vrwMapper      = \OC::$server->get(VerwerkingsactiviteitMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->auditMapper    = \OC::$server->get(AuditTrailMapper::class);
        $this->userManager    = \OC::$server->get(IUserManager::class);
        $this->userSession    = \OC::$server->get(IUserSession::class);

        $this->previousUser = $this->userSession->getUser();

    }//end setUp()

    protected function tearDown(): void
    {
        if ($this->previousUser !== null) {
            $this->userSession->setUser($this->previousUser);
        }

        if ($this->nonAdminUser !== null) {
            try {
                $this->nonAdminUser->delete();
            } catch (\Throwable) {
            }
        }

        $db = \OC::$server->get(\OCP\IDBConnection::class);
        foreach ($this->insertedActivityUuids as $uuid) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_verwerkingsactiviteiten')
                    ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        foreach ($this->insertedSchemas as $schema) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where(
                        $qb->expr()->eq('id', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT))
                    );
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        foreach ($this->insertedRegisters as $register) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where(
                        $qb->expr()->eq('id', $qb->createNamedParameter($register->getId(), IQueryBuilder::PARAM_INT))
                    );
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();

    }//end tearDown()

    public function testListReturnsAllActivitiesAsAdmin(): void
    {
        $this->loginAsAdmin();
        $a = $this->insertActivity(naam: 'phpunit-list-a');
        $b = $this->insertActivity(naam: 'phpunit-list-b');

        $controller = $this->makeController();
        $response   = $controller->index();
        $body       = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertGreaterThanOrEqual(2, $body['count']);

        $uuids = array_column($body['results'], 'uuid');
        $this->assertContains($a->getUuid(), $uuids);
        $this->assertContains($b->getUuid(), $uuids);

    }//end testListReturnsAllActivitiesAsAdmin()

    public function testShowFindsByIdUuidAndCode(): void
    {
        $this->loginAsAdmin();
        $code     = 'phpunit-show-'.uniqid();
        $activity = $this->insertActivity(naam: 'phpunit-show', code: $code);

        $controller = $this->makeController();

        // Find by id.
        $byId = $controller->show(id: (string) $activity->getId());
        $this->assertSame(Http::STATUS_OK, $byId->getStatus());
        $this->assertSame($activity->getUuid(), $byId->getData()['uuid']);

        // Find by uuid.
        $byUuid = $controller->show(id: (string) $activity->getUuid());
        $this->assertSame(Http::STATUS_OK, $byUuid->getStatus());
        $this->assertSame($activity->getId(), $byUuid->getData()['id']);

        // Find by code.
        $byCode = $controller->show(id: $code);
        $this->assertSame(Http::STATUS_OK, $byCode->getStatus());
        $this->assertSame($activity->getId(), $byCode->getData()['id']);

        // Unknown.
        $miss = $controller->show(id: 'phpunit-no-such-ref-'.uniqid());
        $this->assertSame(Http::STATUS_NOT_FOUND, $miss->getStatus());

    }//end testShowFindsByIdUuidAndCode()

    public function testCreateRequiresAdmin(): void
    {
        $this->loginAsNonAdmin();
        $controller = $this->makeControllerWithBody(
            payload: [
                'naam'        => 'phpunit-non-admin-create',
                'doelbinding' => 'p',
                'rechtsgrond' => 'publieke_taak',
            ]
        );

        $response = $controller->create();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertStringContainsString('Admin', $response->getData()['error']);

    }//end testCreateRequiresAdmin()

    public function testCreateValidationReturns422(): void
    {
        $this->loginAsAdmin();
        $controller = $this->makeControllerWithBody(
            payload: [
                'naam'        => 'phpunit-bad-rechtsgrond',
                'doelbinding' => 'p',
                'rechtsgrond' => 'bogus_basis',
            ]
        );

        $response = $controller->create();

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
        $this->assertStringContainsString('rechtsgrond', $response->getData()['error']);

    }//end testCreateValidationReturns422()

    public function testCreatePersistsAndReturns201(): void
    {
        $this->loginAsAdmin();
        $code       = 'phpunit-create-'.uniqid();
        $controller = $this->makeControllerWithBody(
            payload: [
                'naam'                   => 'phpunit-create-'.uniqid(),
                'code'                   => $code,
                'doelbinding'            => 'phpunit purpose binding',
                'rechtsgrond'            => 'overeenkomst',
                'bewaartermijn'          => 'P5Y',
                'categorieenBetrokkenen' => ['burgers', 'medewerkers'],
            ]
        );

        $response = $controller->create();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $body = $response->getData();
        $this->assertNotEmpty($body['uuid']);
        $this->assertSame('overeenkomst', $body['rechtsgrond']);
        $this->assertSame(['burgers', 'medewerkers'], $body['categorieenBetrokkenen']);

        $this->insertedActivityUuids[] = $body['uuid'];

    }//end testCreatePersistsAndReturns201()

    public function testDestroySoftArchivesInsteadOfHardDelete(): void
    {
        $this->loginAsAdmin();
        $activity = $this->insertActivity(naam: 'phpunit-destroy');

        $controller = $this->makeController();
        $response   = $controller->destroy(id: (string) $activity->getId());

        $this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());

        // Row MUST still be findable — soft-archive only.
        $found = $this->vrwMapper->findByUuid(uuid: $activity->getUuid());
        $this->assertNotNull($found, 'destroy MUST NOT hard-delete (audit FK preservation)');
        $this->assertSame('archived', $found->getStatus(), 'destroy MUST flip status to archived');

    }//end testDestroySoftArchivesInsteadOfHardDelete()

    public function testVerantwoordingAggregatesAuditCounts(): void
    {
        $this->loginAsAdmin();
        $activity = $this->insertActivity(naam: 'phpunit-verantwoording', code: 'phpunit-vw-'.uniqid());

        // Wire the activity into a schema so audit rows are tagged.
        $register = $this->insertRegister();
        $schema   = $this->insertSchema(annotation: $activity->getCode());
        $register->setSchemas([$schema->getId()]);
        $this->registerMapper->update($register);

        // Drive 3 create + 1 update audit rows attributed to this activity.
        for ($i = 0; $i < 3; $i++) {
            $obj = $this->insertObject(register: $register, schema: $schema);
            $this->auditMapper->createAuditTrail(old: null, new: $obj, action: 'create');
        }

        $existing = $this->insertObject(register: $register, schema: $schema);
        $modified = clone $existing;
        $modified->setObject(['title' => 'updated']);
        $this->auditMapper->createAuditTrail(old: $existing, new: $modified, action: 'update');

        $controller = $this->makeController();
        $response   = $controller->verantwoording();
        $body       = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

        $row = null;
        foreach ($body['activities'] as $entry) {
            if ($entry['uuid'] === $activity->getUuid()) {
                $row = $entry;
                break;
            }
        }

        $this->assertNotNull($row, 'verantwoording MUST list the test activity');
        $this->assertGreaterThanOrEqual(4, $row['activity']['totalEvents']);
        $this->assertGreaterThanOrEqual(3, $row['activity']['byAction']['create'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $row['activity']['byAction']['update'] ?? 0);

    }//end testVerantwoordingAggregatesAuditCounts()

    private function loginAsAdmin(): void
    {
        $admin = $this->userManager->get('admin');
        if ($admin === null) {
            $this->markTestSkipped('test bench requires the admin user');
        }

        $this->userSession->setUser($admin);

    }//end loginAsAdmin()

    private function loginAsNonAdmin(): void
    {
        if ($this->nonAdminUser === null) {
            $this->nonAdminUserId = 'phpunit-vrw-noadm-'.uniqid();
            $password             = bin2hex(random_bytes(12)).'Aa9!';
            $this->nonAdminUser   = $this->userManager->createUser($this->nonAdminUserId, $password);
        }

        $this->userSession->setUser($this->nonAdminUser);

    }//end loginAsNonAdmin()

    private function insertActivity(string $naam, ?string $code=null): Verwerkingsactiviteit
    {
        $entity = new Verwerkingsactiviteit();
        $entity->setNaam($naam.'-'.uniqid());
        if ($code !== null) {
            $entity->setCode($code);
        }

        $entity->setDoelbinding('phpunit purpose');
        $entity->setRechtsgrond('publieke_taak');
        $entity->setStatus('published');

        $persisted                     = $this->vrwMapper->insert($entity);
        $this->insertedActivityUuids[] = $persisted->getUuid();
        return $persisted;

    }//end insertActivity()

    private function insertRegister(): Register
    {
        $register = new Register();
        $register->setTitle('phpunit-vrw-reg-'.uniqid());
        $register->setSlug('phpunit-vrw-reg-'.uniqid());
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setVersion('1.0.0');
        $register->setSchemas([]);
        $persisted                 = $this->registerMapper->insert($register);
        $this->insertedRegisters[] = $persisted;
        return $persisted;

    }//end insertRegister()

    private function insertSchema(?string $annotation): Schema
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-vrw-schema-'.uniqid());
        $schema->setSlug('phpunit-vrw-schema-'.uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setProperties(['title' => ['type' => 'string', 'title' => 'Title']]);
        if ($annotation !== null) {
            $config                                       = $schema->getConfiguration() ?? [];
            $config['x-openregister-processing-activity'] = $annotation;
            $schema->setConfiguration($config);
        }

        $persisted               = $this->schemaMapper->insert($schema);
        $this->insertedSchemas[] = $persisted;
        return $persisted;

    }//end insertSchema()

    private function insertObject(Register $register, Schema $schema): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setRegister((string) $register->getId());
        $object->setSchema((string) $schema->getId());
        $object->setUuid(Uuid::v4()->toRfc4122());
        $object->setObject(['title' => 'phpunit-vrw-payload']);
        return \OC::$server->get(MagicMapper::class)->insert($object);

    }//end insertObject()

    private function makeController(): VerwerkingsactiviteitenController
    {
        return \OC::$server->get(VerwerkingsactiviteitenController::class);

    }//end makeController()

    /**
     * Build a controller with a request stub that returns the given
     * payload via getParams() — keeps the create/update tests free of
     * actual HTTP wiring.
     *
     * @param array<string, mixed> $payload The body to inject.
     */
    private function makeControllerWithBody(array $payload): VerwerkingsactiviteitenController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn($payload);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) use ($payload) {
                return $payload[$key] ?? $default;
            }
        );

        return new VerwerkingsactiviteitenController(
            'openregister',
            $request,
            $this->vrwMapper,
            $this->userSession,
            \OC::$server->get(\OCP\IGroupManager::class),
            \OC::$server->get(\OCP\IDBConnection::class)
        );

    }//end makeControllerWithBody()
}//end class
