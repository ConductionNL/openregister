<?php

/**
 * Integration tests for the unified RBAC operator-condition matcher.
 *
 * Replaces the manual-smoke verification (tasks 6.1-6.3) with automated
 * coverage. The bug being protected against: schema-level RBAC with
 * operator rules like `{"publishDate": {"$lte": "$now"}}` was previously
 * evaluated against a broken grammar that returned 500 errors for
 * anonymous callers and inconsistent results for authenticated ones.
 * After unification, both paths flow through `ConditionMatcher` and
 * behave consistently with the rest of OpenRegister's match semantics.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class RbacOperatorMatchingIntegrationTest extends TestCase
{
    private PermissionHandler $permissionHandler;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private MagicMapper $objectMapper;

    private ?Register $testRegister = null;
    /** @var Schema[] */
    private array $testSchemas = [];
    private ?string $createdTable = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionHandler = \OC::$server->get(PermissionHandler::class);
        $this->registerMapper    = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper      = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper      = \OC::$server->get(MagicMapper::class);
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);

        foreach ($this->testSchemas as $schema) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        if ($this->testRegister !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testRegister->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        if ($this->createdTable !== null) {
            try {
                $db->prepare("DROP TABLE IF EXISTS \"{$this->createdTable}\"")->execute();
            } catch (\Throwable $e) {
                // best effort
            }
        }

        parent::tearDown();
    }

    public function testPastDatedObjectIsReadableAnonymouslyViaLteNow(): void
    {
        // Replaces task 6.1 manual smoke: a publication scheduled in the
        // past with `read: [{group: public, match: {publishDate: {$lte: $now}}}]`
        // MUST be readable by an anonymous caller. Pre-fix this returned 500.
        $schema = $this->createSchemaWithReadRule([
            ['group' => 'public', 'match' => ['publishDate' => ['$lte' => '$now']]],
        ]);

        $past = $this->makeObject($schema, ['publishDate' => '2020-01-01T00:00:00+00:00']);
        $allowed = $this->permissionHandler->hasPermission(
            schema: $schema,
            action: 'read',
            userId: null,            // anonymous
            objectOwner: null,
            _rbac: true,
            object: $past
        );

        $this->assertTrue($allowed, 'past-dated publication MUST be readable anonymously when publishDate <= now');
    }

    public function testFutureDatedObjectIsNotReadableAnonymouslyViaLteNow(): void
    {
        // Replaces task 6.2: future-dated publication MUST NOT be readable.
        // Pre-fix this would have returned 500 (broken grammar evaluation
        // with no userId crashed). Now it cleanly returns false.
        $schema = $this->createSchemaWithReadRule([
            ['group' => 'public', 'match' => ['publishDate' => ['$lte' => '$now']]],
        ]);

        $future = $this->makeObject($schema, ['publishDate' => '2099-01-01T00:00:00+00:00']);
        $allowed = $this->permissionHandler->hasPermission(
            schema: $schema,
            action: 'read',
            userId: null,
            objectOwner: null,
            _rbac: true,
            object: $future
        );

        $this->assertFalse($allowed, 'future-dated publication MUST NOT be readable anonymously when publishDate > now');
    }

    public function testInOperatorIncludesAndExcludesByValueList(): void
    {
        // Drives the `$in` operator through the same anonymous-public path
        // as test 1 — past/future-dated semantics are already proven there;
        // this test specifically verifies that the `$in` array translation
        // works against real schema data. The failing-then-passing fixture
        // for the originating bug also exercised an array-shaped operator
        // on the public path, so this is the closer regression mirror.
        $schema = $this->createSchemaWithReadRule([
            ['group' => 'public', 'match' => ['status' => ['$in' => ['open', 'review']]]],
        ]);

        $open = $this->makeObject($schema, ['status' => 'open']);
        $allowed = $this->permissionHandler->hasPermission(
            schema: $schema,
            action: 'read',
            userId: null,
            objectOwner: null,
            _rbac: true,
            object: $open
        );
        $this->assertTrue($allowed, 'object with status in [open, review] MUST be readable anonymously');

        $closed = $this->makeObject($schema, ['status' => 'closed']);
        $denied = $this->permissionHandler->hasPermission(
            schema: $schema,
            action: 'read',
            userId: null,
            objectOwner: null,
            _rbac: true,
            object: $closed
        );
        $this->assertFalse($denied, 'object with status not in [open, review] MUST NOT be readable anonymously');
    }

    public function testEmptyMatchReturnsAllowsRule(): void
    {
        // Sanity: a rule with no `match` (group-only) should still allow on
        // group membership alone. The unified matcher must NOT regress this
        // baseline by requiring a match block.
        $schema = $this->createSchemaWithReadRule([
            ['group' => 'public'], // no match → unconditional for public group
        ]);

        $obj = $this->makeObject($schema, ['title' => 'no rule needed']);
        $allowed = $this->permissionHandler->hasPermission(
            schema: $schema,
            action: 'read',
            userId: null,
            objectOwner: null,
            _rbac: true,
            object: $obj
        );

        $this->assertTrue($allowed, 'group-only rule MUST grant access without requiring a match block');
    }

    /**
     * Build a register + schema with the supplied read-rule list.
     *
     * @param array<int, array<string, mixed>> $readRules
     */
    private function createSchemaWithReadRule(array $readRules): Schema
    {
        if ($this->testRegister === null) {
            $register = new Register();
            $register->setTitle('phpunit-rbac-' . uniqid());
            $register->setDescription('RBAC operator matching tests');
            $register->setUuid(Uuid::v4()->toRfc4122());
            $register->setSlug('phpunit-rbac-' . uniqid());
            $register->setSchemas([]);
            $this->testRegister = $this->registerMapper->insert($register);
        }

        $schema = new Schema();
        $schema->setTitle('phpunit-rbac-schema-' . uniqid());
        $schema->setDescription('Schema with operator-rule RBAC');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-rbac-schema-' . uniqid());
        $schema->setProperties([
            'title'       => ['type' => 'string', 'title' => 'Title'],
            'owner'       => ['type' => 'string', 'title' => 'Owner'],
            'publishDate' => ['type' => 'string', 'title' => 'Publish Date', 'format' => 'date-time'],
        ]);
        $schema->setAuthorization(['read' => $readRules]);

        $schema = $this->schemaMapper->insert($schema);
        $this->testSchemas[] = $schema;

        $this->testRegister->setSchemas(array_merge($this->testRegister->getSchemas(), [$schema->getId()]));
        $this->registerMapper->update($this->testRegister);

        if ($this->createdTable === null) {
            $this->objectMapper->ensureTableForRegisterSchema($this->testRegister, $schema);
            $this->createdTable = 'oc_' . $this->objectMapper->getTableNameForRegisterSchema($this->testRegister, $schema);
        } else {
            // Subsequent schemas share the register but get their own table.
            $this->objectMapper->ensureTableForRegisterSchema($this->testRegister, $schema);
        }

        return $schema;
    }

    private function makeObject(Schema $schema, array $data): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $this->testRegister->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject($data);
        if (isset($data['owner']) === true) {
            $entity->setOwner((string) $data['owner']);
        }
        return $entity;
    }
}
