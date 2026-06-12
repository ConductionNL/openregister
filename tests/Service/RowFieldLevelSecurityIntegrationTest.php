<?php

/**
 * Integration tests for row-level + field-level security.
 *
 * Verifies the property-authorization metadata flow on real Schema
 * entities and the `PropertyRbacHandler::filterReadableProperties`
 * filtering behaviour. RLS (schema-level conditional auth) is already
 * covered by `RbacOperatorMatchingIntegrationTest`; this test focuses
 * on FLS — per-property `authorization` blocks.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class RowFieldLevelSecurityIntegrationTest extends TestCase
{
    private PropertyRbacHandler $propertyRbacHandler;
    private SchemaMapper $schemaMapper;

    private ?Schema $authedSchema = null;
    private ?Schema $plainSchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->propertyRbacHandler = \OC::$server->get(PropertyRbacHandler::class);
        $this->schemaMapper        = \OC::$server->get(SchemaMapper::class);

        // Login as admin so the handler has a session — needed by isAdmin().
        $userManager = \OC::$server->get(IUserManager::class);
        $userSession = \OC::$server->get(IUserSession::class);
        $admin       = $userManager->get('admin');
        if ($admin instanceof IUser) {
            $userSession->setUser($admin);
        }

        $this->createTestFixtures();
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);
        foreach ([$this->authedSchema, $this->plainSchema] as $schema) {
            if ($schema === null) {
                continue;
            }
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        parent::tearDown();
    }

    public function testHasPropertyAuthorizationDetectsAuthorizedProperties(): void
    {
        $this->assertTrue(
            $this->authedSchema->hasPropertyAuthorization(),
            'schema with property-level auth blocks MUST report hasPropertyAuthorization() === true'
        );

        $this->assertFalse(
            $this->plainSchema->hasPropertyAuthorization(),
            'schema without property-level auth blocks MUST report hasPropertyAuthorization() === false'
        );
    }

    public function testGetPropertiesWithAuthorizationReturnsOnlyAuthorizedOnes(): void
    {
        $authedProps = $this->authedSchema->getPropertiesWithAuthorization();

        // Test fixture has 3 properties: title (no auth), salary (read=admin),
        // ssn (read=hr, manage). Only the latter two MUST be returned.
        $this->assertArrayNotHasKey('title', $authedProps, 'unauthorized properties MUST NOT appear in getPropertiesWithAuthorization');
        $this->assertArrayHasKey('salary', $authedProps);
        $this->assertArrayHasKey('ssn', $authedProps);

        // The returned value MUST be the actual authorization rule block.
        $salaryRules = $authedProps['salary'];
        $this->assertArrayHasKey('read', $salaryRules);
        $this->assertSame('admin', $salaryRules['read'][0]['group'] ?? null);
    }

    public function testGetPropertyAuthorizationReturnsSpecificRule(): void
    {
        $rule = $this->authedSchema->getPropertyAuthorization('salary');
        $this->assertIsArray($rule);
        $this->assertArrayHasKey('read', $rule);

        $this->assertNull(
            $this->authedSchema->getPropertyAuthorization('title'),
            'unauthorized properties MUST return null from getPropertyAuthorization'
        );

        $this->assertNull(
            $this->authedSchema->getPropertyAuthorization('does-not-exist'),
            'unknown property name MUST return null'
        );
    }

    public function testFilterReadablePropertiesPassesThroughForUnauthorizedSchema(): void
    {
        // Schema with no property-level auth — handler MUST pass the
        // object through unchanged regardless of caller.
        $object = ['title' => 'plain object', 'public' => 'visible to everyone'];

        $filtered = $this->propertyRbacHandler->filterReadableProperties(
            $this->plainSchema,
            $object
        );
        $this->assertSame($object, $filtered, 'schema without property auth MUST pass through unchanged');
    }

    public function testFilterReadablePropertiesAdminBypass(): void
    {
        // Admin bypasses property-level RBAC. Schema has FLS rules but
        // admin sees every property. Verifies the bypass short-circuit
        // is wired before any rule evaluation.
        $object = [
            'title'  => 'admin sees everything',
            'salary' => '95000',
            'ssn'    => '123-45-6789',
        ];

        $filtered = $this->propertyRbacHandler->filterReadableProperties(
            $this->authedSchema,
            $object
        );

        $this->assertSame(
            $object,
            $filtered,
            'admin caller MUST receive every property even when FLS rules are present'
        );
    }

    public function testCanReadPropertyForAdminAlwaysTrue(): void
    {
        $object = ['title' => 'x', 'salary' => '95000', 'ssn' => '123-45-6789'];

        $this->assertTrue($this->propertyRbacHandler->canReadProperty($this->authedSchema, 'title',  $object));
        $this->assertTrue($this->propertyRbacHandler->canReadProperty($this->authedSchema, 'salary', $object));
        $this->assertTrue($this->propertyRbacHandler->canReadProperty($this->authedSchema, 'ssn',    $object));
    }

    public function testCanUpdatePropertyForAdminAlwaysTrue(): void
    {
        $object = ['title' => 'x', 'salary' => '95000'];

        $this->assertTrue($this->propertyRbacHandler->canUpdateProperty($this->authedSchema, 'salary', $object));
        $this->assertTrue($this->propertyRbacHandler->canUpdateProperty($this->authedSchema, 'salary', $object, isCreate: true));
    }

    private function createTestFixtures(): void
    {
        // Schema WITH property-level authorization rules.
        $authed = new Schema();
        $authed->setTitle('phpunit-fls-authed-' . uniqid());
        $authed->setUuid(Uuid::v4()->toRfc4122());
        $authed->setSlug('phpunit-fls-authed-' . uniqid());
        $authed->setProperties([
            'title'  => ['type' => 'string', 'title' => 'Title'],
            'salary' => [
                'type'          => 'number',
                'title'         => 'Salary',
                'authorization' => [
                    'read' => [['group' => 'admin']],
                ],
            ],
            'ssn' => [
                'type'          => 'string',
                'title'         => 'Social security number',
                'authorization' => [
                    'read'   => [['group' => 'hr']],
                    'manage' => [['group' => 'hr']],
                ],
            ],
        ]);
        $this->authedSchema = $this->schemaMapper->insert($authed);

        // Schema WITHOUT property-level authorization (sanity baseline).
        $plain = new Schema();
        $plain->setTitle('phpunit-fls-plain-' . uniqid());
        $plain->setUuid(Uuid::v4()->toRfc4122());
        $plain->setSlug('phpunit-fls-plain-' . uniqid());
        $plain->setProperties([
            'title'  => ['type' => 'string', 'title' => 'Title'],
            'public' => ['type' => 'string', 'title' => 'Public field'],
        ]);
        $this->plainSchema = $this->schemaMapper->insert($plain);
    }
}
