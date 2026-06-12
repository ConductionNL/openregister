<?php

/**
 * Integration tests for the OpenRegister activity provider end-to-end.
 *
 * The unit tests in `tests/Unit/Service/ActivityServiceTest` cover the
 * publish-call correctness (subject, type, objectId, dual-notification,
 * fallbacks). This integration test goes one layer deeper: it triggers
 * a real `ActivityService::publish*` call and then reads back from the
 * `oc_activity` table to verify the event landed in Nextcloud's
 * activity stream — which is what the manual-smoke tasks in the spec
 * were verifying via the Activity app UI.
 *
 * UI rendering (filters, formatters, settings group) is standard NC
 * Activity-app behaviour and not OpenRegister code, so it remains
 * out of scope for automated coverage.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ActivityService;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class ActivityProviderIntegrationTest extends TestCase
{
    private ActivityService $activityService;
    private IDBConnection $db;
    private IUserSession $userSession;

    /** @var int[] */
    private array $insertedActivityIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->activityService = \OC::$server->get(ActivityService::class);
        $this->db              = \OC::$server->get(IDBConnection::class);
        $this->userSession     = \OC::$server->get(IUserSession::class);

        // Activities require an active user — the publish path uses
        // userSession->getUser() as the activity author.
        $userManager = \OC::$server->get(IUserManager::class);
        $admin       = $userManager->get('admin');
        if ($admin instanceof IUser) {
            $this->userSession->setUser($admin);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any activity rows we created so the activity stream
        // doesn't accumulate test residue across runs.
        foreach ($this->insertedActivityIds as $id) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('activity')
                    ->where($qb->expr()->eq('activity_id', $qb->createNamedParameter($id)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        parent::tearDown();
    }

    public function testPublishObjectCreatedLandsInActivityStream(): void
    {
        $object = $this->makeObject('Test object created');

        $beforeMaxId = $this->maxActivityId();
        $this->activityService->publishObjectCreated($object);

        $rows = $this->fetchActivitiesAfter($beforeMaxId);
        $this->assertNotEmpty($rows, 'publishObjectCreated MUST insert at least one row in oc_activity');

        $created = $this->findOurActivity($rows, subject: 'object_created', objectId: (int) $object->getId());
        $this->assertNotNull($created, 'object_created event MUST appear in the activity stream');
        $this->assertSame('openregister', $created['app']);
        $this->assertSame('openregister_objects', $created['type']);
        $this->assertSame('object', $created['object_type']);
        $this->assertSame('admin', $created['user']);
        $this->assertSame('admin', $created['affecteduser']);
    }

    public function testPublishObjectUpdatedLandsInActivityStream(): void
    {
        $newObject = $this->makeObject('Test object updated v2');

        $beforeMaxId = $this->maxActivityId();
        $this->activityService->publishObjectUpdated($newObject);

        $rows = $this->fetchActivitiesAfter($beforeMaxId);
        $updated = $this->findOurActivity($rows, subject: 'object_updated', objectId: (int) $newObject->getId());
        $this->assertNotNull($updated, 'object_updated event MUST appear in the activity stream');
    }

    public function testPublishObjectDeletedLandsInActivityStream(): void
    {
        $object = $this->makeObject('Test object deleted');

        $beforeMaxId = $this->maxActivityId();
        $this->activityService->publishObjectDeleted($object);

        $rows = $this->fetchActivitiesAfter($beforeMaxId);
        $deleted = $this->findOurActivity($rows, subject: 'object_deleted', objectId: (int) $object->getId());
        $this->assertNotNull($deleted, 'object_deleted event MUST appear in the activity stream');
    }

    public function testPublishRegisterCreatedLandsInActivityStream(): void
    {
        $register = $this->makeRegister('Test register');

        $beforeMaxId = $this->maxActivityId();
        $this->activityService->publishRegisterCreated($register);

        $rows = $this->fetchActivitiesAfter($beforeMaxId);
        $created = $this->findOurActivity($rows, subject: 'register_created', objectId: (int) $register->getId());
        $this->assertNotNull($created, 'register_created event MUST appear in the activity stream');
        $this->assertSame('openregister_registers', $created['type']);
        $this->assertSame('register', $created['object_type']);
    }

    public function testPublishSchemaCreatedLandsInActivityStream(): void
    {
        $schema = $this->makeSchema('Test schema');

        $beforeMaxId = $this->maxActivityId();
        $this->activityService->publishSchemaCreated($schema);

        $rows = $this->fetchActivitiesAfter($beforeMaxId);
        $created = $this->findOurActivity($rows, subject: 'schema_created', objectId: (int) $schema->getId());
        $this->assertNotNull($created, 'schema_created event MUST appear in the activity stream');
        $this->assertSame('openregister_schemas', $created['type']);
        $this->assertSame('schema', $created['object_type']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchActivitiesAfter(int $afterId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('activity_id', 'app', 'type', 'subject', 'subjectparams', 'object_type', 'object_id', 'user', 'affecteduser')
            ->from('activity')
            ->where($qb->expr()->gt('activity_id', $qb->createNamedParameter($afterId)))
            ->andWhere($qb->expr()->eq('app', $qb->createNamedParameter('openregister')))
            ->orderBy('activity_id', 'DESC');

        $stmt = $qb->executeQuery();
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();

        foreach ($rows as $row) {
            $this->insertedActivityIds[] = (int) $row['activity_id'];
        }
        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function findOurActivity(array $rows, string $subject, int $objectId): ?array
    {
        foreach ($rows as $row) {
            if ((string) $row['subject'] === $subject && (int) $row['object_id'] === $objectId) {
                return $row;
            }
        }
        return null;
    }

    private function maxActivityId(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COALESCE(MAX(activity_id), 0) AS max_id'))
            ->from('activity');
        $stmt = $qb->executeQuery();
        $row  = $stmt->fetch();
        $stmt->closeCursor();
        return (int) ($row['max_id'] ?? 0);
    }

    private function makeObject(string $name): ObjectEntity
    {
        $object = new ObjectEntity();
        // The IDs we use here aren't real DB rows — the activity row only
        // requires `object_id` to be a stable integer for grouping. Use a
        // high number to avoid collision with any real existing object.
        $object->setId(random_int(900000, 999999));
        $object->setUuid(Uuid::v4()->toRfc4122());
        $object->setName($name);
        $object->setOwner('admin');
        $object->setRegister('1');
        $object->setSchema('1');
        return $object;
    }

    private function makeRegister(string $title): Register
    {
        $register = new Register();
        $register->setId(random_int(900000, 999999));
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setTitle($title);
        $register->setSlug('phpunit-activity-' . uniqid());
        $register->setOwner('admin');
        return $register;
    }

    private function makeSchema(string $title): Schema
    {
        $schema = new Schema();
        $schema->setId(random_int(900000, 999999));
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setTitle($title);
        $schema->setSlug('phpunit-activity-schema-' . uniqid());
        $schema->setOwner('admin');
        return $schema;
    }
}
