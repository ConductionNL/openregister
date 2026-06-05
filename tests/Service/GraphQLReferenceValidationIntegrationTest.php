<?php

/**
 * Integration tests proving GraphQL mutations enforce reference-existence
 * validation by routing through the unified `SaveObject::saveObject` write
 * path. Closes the rbac-validation alignment task on the
 * `reference-existence-validation` change: GraphQL mutations MUST NOT
 * silently bypass the validation that REST mutations enforce.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use GraphQL\Error\Error;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\ObjectService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class GraphQLReferenceValidationIntegrationTest extends TestCase
{

    private GraphQLResolver $resolver;

    private SaveObject $saveHandler;

    private ObjectService $objectService;

    private RegisterMapper $registerMapper;

    private SchemaMapper $schemaMapper;

    private MagicMapper $objectMapper;

    private ?Register $testRegister = null;

    private ?Schema $targetSchema = null;

    private ?Schema $referrerSchema = null;

    /**
     * @var string[]
     */
    private array $createdObjectUuids = [];

    /**
     * @var string[]
     */
    private array $createdTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver       = \OC::$server->get(GraphQLResolver::class);
        $this->saveHandler    = \OC::$server->get(SaveObject::class);
        $this->objectService  = \OC::$server->get(ObjectService::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper   = \OC::$server->get(MagicMapper::class);

        $this->createTestFixture();
    }//end setUp()

    protected function tearDown(): void
    {
        foreach ($this->createdObjectUuids as $uuid) {
            try {
                $this->objectService->deleteObject($uuid, false, false);
            } catch (\Throwable $e) {
                // best effort
            }
        }

        $db = \OC::$server->get(\OCP\IDBConnection::class);
        foreach ([$this->referrerSchema, $this->targetSchema] as $schema) {
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

        foreach ($this->createdTables as $table) {
            try {
                $db->prepare("DROP TABLE IF EXISTS \"$table\"")->execute();
            } catch (\Throwable $e) {
                // best effort
            }
        }

        parent::tearDown();
    }//end tearDown()

    public function testGraphqlCreateMutationRejectsNonExistentReference(): void
    {
        // The headline contract: a GraphQL `create` mutation that
        // includes a reference to a non-existent target UUID MUST be
        // rejected with a `VALIDATION_ERROR` GraphQL error, NOT silently
        // accepted. This proves the mutation path goes through the
        // unified `SaveObject::saveObject` validation (and not a parallel
        // GraphQL-only write path).
        $badUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        try {
            $this->resolver->resolveCreate(
                schema: $this->referrerSchema,
                args: [
                    'input' => [
                        'title'      => 'GraphQL referrer with bad UUID',
                        'targetUuid' => $badUuid,
                    ],
                ]
            );
            $this->fail('GraphQL mutation MUST reject non-existent reference UUID');
        } catch (Error $e) {
            $extensions = $e->getExtensions() ?? [];
            $this->assertSame(
                'VALIDATION_ERROR',
                $extensions['code'] ?? null,
                'GraphQL error MUST carry code=VALIDATION_ERROR for reference-existence rejections'
            );
            $this->assertNotEmpty(
                $e->getMessage(),
                'GraphQL error MUST carry the underlying validation message for the client'
            );
        }//end try
    }//end testGraphqlCreateMutationRejectsNonExistentReference()

    public function testGraphqlCreateMutationAcceptsValidReference(): void
    {
        // Sanity check the happy path: a valid existing UUID MUST go
        // through, proving we did not break the GraphQL write path while
        // adding the rejection contract above.
        $target = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->targetSchema,
            ['title' => 'GraphQL valid target'],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $target->getUuid();

        $result = $this->resolver->resolveCreate(
            schema: $this->referrerSchema,
            args: [
                'input' => [
                    'title'      => 'GraphQL referrer with valid UUID',
                    'targetUuid' => $target->getUuid(),
                ],
            ]
        );

        $this->assertIsArray($result, 'create mutation MUST return an array shape');
        $createdUuid = ($result['_uuid'] ?? $result['@self']['uuid'] ?? null);
        $this->assertNotNull($createdUuid, 'created object MUST carry a UUID in the response');
        if (is_string($createdUuid) === true) {
            $this->createdObjectUuids[] = $createdUuid;
        }
    }//end testGraphqlCreateMutationAcceptsValidReference()

    public function testGraphqlUpdateMutationRejectsBadReferenceChange(): void
    {
        // Defence-in-depth: an UPDATE that swaps a reference for a bad
        // UUID MUST be rejected. This exercises the same code path as
        // create but with an existing object, making sure the unified
        // write path applies validation on update too.
        $target = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->targetSchema,
            ['title' => 'GraphQL update target'],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $target->getUuid();

        $referrer = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->referrerSchema,
            ['title' => 'GraphQL referrer V1', 'targetUuid' => $target->getUuid()],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $referrer->getUuid();

        try {
            $result = $this->resolver->resolveUpdate(
                schema: $this->referrerSchema,
                args: [
                    'id'    => $referrer->getUuid(),
                    'input' => [
                        'title'      => 'GraphQL referrer V2',
                        'targetUuid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
                    ],
                ]
            );
            $stored = ($result['targetUuid'] ?? null);
            $this->fail(
                'GraphQL update MUST reject reference change to non-existent UUID. '.'Update silently accepted; stored targetUuid='.var_export($stored, true)
            );
        } catch (Error $e) {
            $extensions = $e->getExtensions() ?? [];
            $this->assertSame(
                'VALIDATION_ERROR',
                $extensions['code'] ?? null,
                'update path MUST surface VALIDATION_ERROR like the create path'
            );
        }//end try
    }//end testGraphqlUpdateMutationRejectsBadReferenceChange()

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-gqlrefexist-'.uniqid());
        $register->setDescription('GraphQL reference existence validation tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-gqlrefexist-'.uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $target = new Schema();
        $target->setTitle('phpunit-gqlrefexist-target-'.uniqid());
        $target->setDescription('Target of GraphQL reference validation');
        $target->setUuid(Uuid::v4()->toRfc4122());
        $target->setSlug('phpunit-gqlrefexist-target-'.uniqid());
        $target->setProperties(
                [
                    'title' => ['type' => 'string', 'title' => 'Title'],
                ]
                );
        $this->targetSchema = $this->schemaMapper->insert($target);

        $referrer = new Schema();
        $referrer->setTitle('phpunit-gqlrefexist-referrer-'.uniqid());
        $referrer->setDescription('Referrer schema for GraphQL validation tests');
        $referrer->setUuid(Uuid::v4()->toRfc4122());
        $referrer->setSlug('phpunit-gqlrefexist-referrer-'.uniqid());
        $referrer->setProperties(
                [
                    'title'      => ['type' => 'string', 'title' => 'Title'],
                    'targetUuid' => [
                        'type'              => 'string',
                        'title'             => 'Target reference',
                        '$ref'              => '#/components/schemas/'.$target->getSlug(),
                        'validateReference' => true,
                    ],
                ]
                );
        $this->referrerSchema = $this->schemaMapper->insert($referrer);

        $this->testRegister->setSchemas([$this->targetSchema->getId(), $this->referrerSchema->getId()]);
        $this->registerMapper->update($this->testRegister);

        $register = $this->testRegister;
        $this->objectMapper->ensureTableForRegisterSchema($register, $this->targetSchema);
        $this->objectMapper->ensureTableForRegisterSchema($register, $this->referrerSchema);
        $targetTable           = $this->objectMapper->getTableNameForRegisterSchema($register, $this->targetSchema);
        $referrerTable         = $this->objectMapper->getTableNameForRegisterSchema($register, $this->referrerSchema);
        $this->createdTables[] = 'oc_'.$targetTable;
        $this->createdTables[] = 'oc_'.$referrerTable;
    }//end createTestFixture()
}//end class
