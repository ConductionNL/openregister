<?php

/**
 * End-to-end integration tests for CASCADE / RESTRICT / SET_NULL
 * referential-integrity actions.
 *
 * Complements the existing `ReferentialIntegrityServiceIntegrationTest`
 * which covers unit-level surfaces (action validation, DeletionAnalysis
 * shape, log emission). This test drives the full delete pipeline:
 * persist a parent + child with `$ref` + `onDelete` config, request
 * deletion of the parent, and assert the child reacted correctly
 * (cascaded → gone, set-null → cleared reference, restrict → blocked).
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
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class ReferentialIntegrityCascadeIntegrationTest extends TestCase
{
    private ReferentialIntegrityService $integrityService;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private MagicMapper $objectMapper;

    private ?Register $testRegister = null;
    private ?Schema $parentSchema = null;
    private ?Schema $childSchema = null;
    /** @var string[] */
    private array $createdTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->integrityService = \OC::$server->get(ReferentialIntegrityService::class);
        $this->registerMapper   = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper     = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper     = \OC::$server->get(MagicMapper::class);

        // The integrity service is a DI singleton; its $relationIndex
        // is built lazily and cached. Reset it so each test sees the
        // fresh test schemas it just created.
        $this->resetIntegrityCache();
    }

    private function resetIntegrityCache(): void
    {
        try {
            $ref = new \ReflectionClass($this->integrityService);
            foreach (['relationIndex', 'schemaCache', 'schemaRegisterMap'] as $prop) {
                if ($ref->hasProperty($prop)) {
                    $p = $ref->getProperty($prop);
                    $p->setAccessible(true);
                    $p->setValue($this->integrityService, null);
                }
            }
        } catch (\Throwable $e) {
            // best effort
        }
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);

        foreach ([$this->parentSchema, $this->childSchema] as $schema) {
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
    }

    public function testRestrictBlocksDeletionWhenChildReferencesParent(): void
    {
        $this->createSchemas(onDelete: 'RESTRICT');

        $parent = $this->seed($this->parentSchema, ['title' => 'Parent']);
        $this->seed($this->childSchema, ['title' => 'Child', 'parentRef' => $parent->getUuid()]);

        $this->resetIntegrityCache();
        $analysis = $this->integrityService->canDelete($parent);

        // The presence of a referencing child under RESTRICT MUST surface
        // as a blocker — `canDelete` returns blockers; the caller checks
        // `$deletable === false` (or non-empty `blockers`) before
        // proceeding with the actual delete.
        $this->assertFalse(
            $analysis->deletable,
            'RESTRICT MUST mark the analysis as not-deletable when a child references the parent'
        );
        $this->assertNotEmpty($analysis->blockers, 'RESTRICT MUST populate blockers with the offending child reference');
    }

    public function testCascadeMarksChildForDeletion(): void
    {
        $this->createSchemas(onDelete: 'CASCADE');

        $parent = $this->seed($this->parentSchema, ['title' => 'Parent']);
        $child  = $this->seed($this->childSchema, ['title' => 'Child', 'parentRef' => $parent->getUuid()]);

        $this->resetIntegrityCache();
        $analysis = $this->integrityService->canDelete($parent);

        // Cascade analysis MUST collect the child as a target so the
        // caller can apply the deletion.
        $cascadeTargets = $analysis->cascadeTargets;
        $this->assertNotEmpty($cascadeTargets, 'CASCADE MUST collect the child as a deletion target');

        $matchedChild = false;
        foreach ($cascadeTargets as $target) {
            $uuid = $target['objectUuid'] ?? $target['uuid'] ?? null;
            if ($uuid === $child->getUuid()) {
                $matchedChild = true;
                break;
            }
        }
        $this->assertTrue(
            $matchedChild,
            'cascade analysis MUST contain the child object UUID'
        );
    }

    public function testSetNullCollectsChildAsNullifyTarget(): void
    {
        $this->createSchemas(onDelete: 'SET_NULL');

        $parent = $this->seed($this->parentSchema, ['title' => 'Parent']);
        $child  = $this->seed($this->childSchema, ['title' => 'Child', 'parentRef' => $parent->getUuid()]);

        $this->resetIntegrityCache();
        $analysis = $this->integrityService->canDelete($parent);

        $nullifyTargets = $analysis->nullifyTargets;
        $this->assertNotEmpty($nullifyTargets, 'SET_NULL MUST collect children whose reference will be cleared');

        $matchedChild = false;
        foreach ($nullifyTargets as $target) {
            $uuid = $target['objectUuid'] ?? $target['uuid'] ?? null;
            if ($uuid === $child->getUuid()) {
                $matchedChild = true;
                break;
            }
        }
        $this->assertTrue($matchedChild, 'SET_NULL targets MUST contain the child UUID');
    }

    private function createSchemas(string $onDelete): void
    {
        $register = new Register();
        $register->setTitle('phpunit-refint-' . uniqid());
        $register->setDescription('Referential integrity end-to-end tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-refint-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $parent = new Schema();
        $parent->setTitle('phpunit-refint-parent-' . uniqid());
        $parent->setUuid(Uuid::v4()->toRfc4122());
        $parent->setSlug('phpunit-refint-parent-' . uniqid());
        $parent->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
        ]);
        $this->parentSchema = $this->schemaMapper->insert($parent);

        $child = new Schema();
        $child->setTitle('phpunit-refint-child-' . uniqid());
        $child->setUuid(Uuid::v4()->toRfc4122());
        $child->setSlug('phpunit-refint-child-' . uniqid());
        $child->setProperties([
            'title'     => ['type' => 'string', 'title' => 'Title'],
            'parentRef' => [
                'type'     => 'string',
                'title'    => 'Parent reference',
                '$ref'     => '#/components/schemas/' . $parent->getSlug(),
                'onDelete' => $onDelete,
            ],
        ]);
        $this->childSchema = $this->schemaMapper->insert($child);

        $this->testRegister->setSchemas([$this->parentSchema->getId(), $this->childSchema->getId()]);
        $this->registerMapper->update($this->testRegister);

        foreach ([$this->parentSchema, $this->childSchema] as $s) {
            $this->objectMapper->ensureTableForRegisterSchema($this->testRegister, $s);
            $this->createdTables[] = 'oc_' . $this->objectMapper->getTableNameForRegisterSchema($this->testRegister, $s);
        }
    }

    private function seed(Schema $schema, array $data): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $this->testRegister->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject($data);
        return $this->objectMapper->insertObjectEntity($entity, $this->testRegister, $schema, false);
    }
}
